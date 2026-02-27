<?php
if ( ! defined('ABSPATH') ) { exit; }

// ---------------------------------------------------------------------------
// REQUIRED HELPERS (missing on your staging file)
// ---------------------------------------------------------------------------

if (!function_exists('hcn_ajax_new_job_id')) {
  function hcn_ajax_new_job_id(): string {
    return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('hcn_', true);
  }
}

if (!function_exists('hcn_ajax_job_key')) {
  function hcn_ajax_job_key(string $job_id): string {
    return 'hcn_job_' . $job_id;
  }
}

add_action('init', function () {
  add_action('wp_ajax_hcn_import_start', 'hcn_import_start_handler');
  add_action('wp_ajax_hcn_import_single', 'hcn_import_single_handler');
  add_action('wp_ajax_hcn_import_finish', 'hcn_import_finish_handler');
  add_action('wp_ajax_hcn_get_log', 'hcn_get_log_handler');
  add_action('wp_ajax_hcn_loggia_test', 'hcn_loggia_test_handler');
  add_action('wp_ajax_hcn_ping', 'hcn_ping_handler');
  add_action('wp_ajax_hcn_loggia_availability_sync', 'hcn_loggia_availability_sync_handler');
});

/**
 * START — build queue via providers
 * POST:
 * - provider: hostfully|loggia|both
 * - mode: all|featured (hostfully)
 * - property_uid: optional (single hostfully)
 * - first_n: optional int (only used for provider=hostfully or provider=loggia; both is still JS-limited)
 */
function hcn_import_start_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'HavenConnect singletons not initialized.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  if (method_exists($logger, 'clear')) {
    $logger->clear();
  }

  // Purge orphan availability rows before a fresh import run
  if (!empty($hc['availability_table']) && method_exists($hc['availability_table'], 'purge_orphan_rows')) {
    $n = $hc['availability_table']->purge_orphan_rows();
    $logger->log("Availability: purged {$n} orphan rows.");
  }

  $provider_key = sanitize_text_field($_POST['provider'] ?? 'hostfully');
  $mode = sanitize_text_field($_POST['mode'] ?? 'all');
  $single_uid = sanitize_text_field($_POST['property_uid'] ?? '');
  $first_n = isset($_POST['first_n']) ? max(0, (int)$_POST['first_n']) : 0;

  if (!in_array($provider_key, ['hostfully', 'loggia', 'both'], true)) {
    $provider_key = 'hostfully';
  }

  $providers = $hc['providers'] ?? [];
  $items = [];

  // Hostfully queue
  if (in_array($provider_key, ['hostfully', 'both'], true)) {
    $hp = $providers['hostfully'] ?? null;
    if (!$hp) {
      $logger->log('Hostfully provider not initialized; skipping.');
    } elseif (!$hp->is_configured()) {
      $logger->log('Hostfully: missing credentials; skipping.');
    } else {
      // Only apply server-side limit when provider=hostfully (avoid weird semantics for both)
      $limit = ($provider_key === 'hostfully' && $first_n > 0) ? $first_n : 0;
      $hf_items = $hp->build_queue($mode, $single_uid, $limit);
      $items = array_merge($items, $hf_items);
    }
  }

  // Loggia queue
  if (in_array($provider_key, ['loggia', 'both'], true)) {
    $lp = $providers['loggia'] ?? null;
    if (!$lp) {
      $logger->log('Loggia provider not initialized; skipping.');
    } elseif (!$lp->is_configured()) {
      $logger->log('Loggia: missing credentials; skipping.');
    } else {
      $limit = ($provider_key === 'loggia' && $first_n > 0) ? $first_n : 0;
      $lg_items = $lp->build_queue($limit);
      $items = array_merge($items, $lg_items);
    }
  }

  if (empty($items)) {
    $logger->log("No properties queued for provider={$provider_key}.");
    $logger->save();
    wp_send_json_error(['message' => 'No properties queued (check credentials and provider configuration).'], 404);
  }

  $job_id = hcn_ajax_new_job_id();
  $key = hcn_ajax_job_key($job_id);

  $queue = [
    'created_at' => time(),
    'total'      => count($items),
    'done'       => 0,
    'provider'   => $provider_key,
    'items'      => $items,
  ];

  set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

  // ✅ CLARIFIED LOG
  $msg = "Queue created (provider={$provider_key}) with {$queue['total']} items.";
  if ($first_n > 0) {
    if ($provider_key === 'both') {
      $msg .= " (Run First N={$first_n} is applied client-side; full queue built.)";
    } else {
      $msg .= " (Server limited to First N={$first_n}.)";
    }
  }

  $logger->log($msg);
  $logger->save();

  wp_send_json_success([
    'job_id'    => $job_id,
    'total'     => $queue['total'],
    'provider'  => $provider_key,
    'items'     => array_map(fn($i) => [
      'uid'      => $i['uid'] ?? ($i['external_id'] ?? ''),
      'name'     => $i['name'] ?? '',
      'provider' => $i['provider'] ?? $provider_key,
    ], $items),
    'message'   => 'Queue created successfully.',
  ]);
}

// ---------------------------------------------------------------------------
// SINGLE — import one item via its provider
// ---------------------------------------------------------------------------
function hcn_import_single_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $job_id = sanitize_text_field($_POST['job_id'] ?? '');
  $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;

  if (!$job_id || $index < 0) {
    wp_send_json_error(['message' => 'Missing job_id or index.'], 400);
  }

  $key = hcn_ajax_job_key($job_id);
  $queue = get_transient($key);

  if (!$queue) {
    wp_send_json_error(['message' => 'Queue expired or missing.'], 410);
  }
  if (!isset($queue['items'][$index])) {
    wp_send_json_error(['message' => 'Invalid index.'], 400);
  }

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'HavenConnect singletons not initialized.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  $item = $queue['items'][$index];
  $provider_key = $item['provider'] ?? 'hostfully';

  $external = (string)($item['external_id'] ?? ($item['uid'] ?? ''));
  $uid = (string)($item['uid'] ?? $external);
  $name = (string)($item['name'] ?? $external);

  $logger->log("Importing {$name} ({$provider_key}:{$external}) …");
  $logger->save();

  $provider = $hc['providers'][$provider_key] ?? null;
  if (!$provider) {
    $logger->log("Provider '{$provider_key}' not found in globals.");
    $logger->save();
    wp_send_json_error(['message' => "Provider '{$provider_key}' not initialized."], 500);
  }

  try {
    $post_id = (int) $provider->import_one($item);

    $queue['done'] = max((int)$queue['done'], $index + 1);
    set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

    $logger->log("Imported {$name} (provider={$provider_key}, post_id={$post_id})");
    $logger->save();

    wp_send_json_success([
      'index'   => $index,
      'uid'     => $uid,
      'name'    => $name,
      'provider'=> $provider_key,
      'post_id' => $post_id,
      'done'    => $queue['done'],
      'total'   => $queue['total'],
      'message' => "Imported {$name}",
    ]);
  } catch (Throwable $e) {
    $logger->log("ERROR importing {$provider_key}:{$external}: " . $e->getMessage());
    $logger->save();

    wp_send_json_error([
      'index'   => $index,
      'uid'     => $uid,
      'name'    => $name,
      'provider'=> $provider_key,
      'message' => $e->getMessage(),
    ], 500);
  }
}