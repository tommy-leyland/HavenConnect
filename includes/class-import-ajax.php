<?php
/**
 * HavenConnect AJAX Import Handlers
 *
 * - hcn_import_start  : builds a transient queue via provider abstraction
 * - hcn_import_single : imports one item from queue via provider abstraction
 * - hcn_import_finish : cleans up queue
 * - hcn_get_log       : live log polling
 * - hcn_loggia_test   : Loggia connection test
 * - hcn_ping          : AJAX health check
 * - hcn_loggia_availability_sync : Loggia availability sync
 */

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------------------------
// Utility helpers
// ---------------------------------------------------------------------------

function hcn_ajax_new_job_id(): string {
  return wp_generate_uuid4();
}

function hcn_ajax_job_key(string $job_id): string {
  return 'hcn_job_' . $job_id;
}

// ---------------------------------------------------------------------------
// Register handlers — after init so singletons are ready
// ---------------------------------------------------------------------------

add_action('init', function () {
  add_action('wp_ajax_hcn_import_start',             'hcn_import_start_handler');
  add_action('wp_ajax_hcn_import_single',            'hcn_import_single_handler');
  add_action('wp_ajax_hcn_import_finish',            'hcn_import_finish_handler');
  add_action('wp_ajax_hcn_get_log',                  'hcn_get_log_handler');
  add_action('wp_ajax_hcn_loggia_test',              'hcn_loggia_test_handler');
  add_action('wp_ajax_hcn_ping',                     'hcn_ping_handler');
  add_action('wp_ajax_hcn_loggia_availability_sync', 'hcn_loggia_availability_sync_handler');
});

// ---------------------------------------------------------------------------
// START — build queue via providers
// ---------------------------------------------------------------------------

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
  $logger->clear();

  // Purge orphan availability rows before a fresh import run
  if (!empty($hc['availability_table'])
      && method_exists($hc['availability_table'], 'purge_orphan_rows')) {
    $n = $hc['availability_table']->purge_orphan_rows();
    $logger->log("Availability: purged {$n} orphan rows.");
  }

  $provider_key = sanitize_text_field($_POST['provider'] ?? 'hostfully');
  $mode         = sanitize_text_field($_POST['mode']     ?? 'all');
  $single_uid   = sanitize_text_field($_POST['property_uid'] ?? '');
  $first_n      = isset($_POST['first_n']) ? max(0, (int)$_POST['first_n']) : 0;

  if (!in_array($provider_key, ['hostfully', 'loggia', 'both'], true)) {
    $provider_key = 'hostfully';
  }

  $providers = $hc['providers'] ?? [];
  $items     = [];

  // --- Hostfully queue ---
  if (in_array($provider_key, ['hostfully', 'both'], true)) {
    $hp = $providers['hostfully'] ?? null;
    if (!$hp) {
      $logger->log('Hostfully provider not initialized; skipping.');
    } elseif (!$hp->is_configured()) {
      $logger->log('Hostfully: missing credentials; skipping.');
    } else {
      $limit      = ($provider_key === 'hostfully' && $first_n > 0) ? $first_n : 0;
      $hf_items   = $hp->build_queue($mode, $single_uid, $limit);
      $items      = array_merge($items, $hf_items);
    }
  }

  // --- Loggia queue ---
  if (in_array($provider_key, ['loggia', 'both'], true)) {
    $lp = $providers['loggia'] ?? null;
    if (!$lp) {
      $logger->log('Loggia provider not initialized; skipping.');
    } elseif (!$lp->is_configured()) {
      $logger->log('Loggia: missing credentials; skipping.');
    } else {
      $limit      = ($provider_key === 'loggia' && $first_n > 0) ? $first_n : 0;
      $lg_items   = $lp->build_queue($limit);
      $items      = array_merge($items, $lg_items);
    }
  }

  if (empty($items)) {
    $logger->log("No properties queued for provider={$provider_key}.");
    $logger->save();
    wp_send_json_error(['message' => 'No properties queued (check credentials and provider configuration).'], 404);
  }

  $job_id = hcn_ajax_new_job_id();
  $key    = hcn_ajax_job_key($job_id);

  $queue = [
    'created_at' => time(),
    'total'      => count($items),
    'done'       => 0,
    'provider'   => $provider_key,
    'items'      => $items,
  ];

  set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

  $logger->log("Queue created (provider={$provider_key}) with {$queue['total']} items.");
  $logger->save();

  wp_send_json_success([
    'job_id'   => $job_id,
    'total'    => $queue['total'],
    'provider' => $provider_key,
    'items'    => array_map(fn($i) => [
      'uid'      => $i['uid']      ?? ($i['external_id'] ?? ''),
      'name'     => $i['name']     ?? '',
      'provider' => $i['provider'] ?? $provider_key,
    ], $items),
    'message'  => 'Queue created successfully.',
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
  $index  = isset($_POST['index']) ? (int)$_POST['index'] : -1;

  if (!$job_id || $index < 0) {
    wp_send_json_error(['message' => 'Missing job_id or index.'], 400);
  }

  $key   = hcn_ajax_job_key($job_id);
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

  $item         = $queue['items'][$index];
  $provider_key = $item['provider']    ?? 'hostfully';
  $external     = (string)($item['external_id'] ?? ($item['uid'] ?? ''));
  $uid          = (string)($item['uid']          ?? $external);
  $name         = (string)($item['name']         ?? $external);

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
      'index'    => $index,
      'uid'      => $uid,
      'name'     => $name,
      'provider' => $provider_key,
      'post_id'  => $post_id,
      'done'     => $queue['done'],
      'total'    => $queue['total'],
      'message'  => "Imported {$name}",
    ]);

  } catch (Throwable $e) {
    $logger->log("ERROR importing {$provider_key}:{$external}: " . $e->getMessage());
    $logger->log($e->getTraceAsString());
    $logger->save();

    wp_send_json_error([
      'index'    => $index,
      'uid'      => $uid,
      'name'     => $name,
      'provider' => $provider_key,
      'message'  => $e->getMessage(),
    ], 500);
  }
}

// ---------------------------------------------------------------------------
// FINISH — cleanup transient
// ---------------------------------------------------------------------------

function hcn_import_finish_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $job_id = sanitize_text_field($_POST['job_id'] ?? '');
  if (!$job_id) {
    wp_send_json_error(['message' => 'Missing job_id.'], 400);
  }

  delete_transient(hcn_ajax_job_key($job_id));

  if (!empty($GLOBALS['havenconnect']['logger'])) {
    $GLOBALS['havenconnect']['logger']->log('Import finished.');
    $GLOBALS['havenconnect']['logger']->save();
  }

  wp_send_json_success(['message' => 'Import completed.']);
}

// ---------------------------------------------------------------------------
// LOGGIA AVAILABILITY SYNC
// ---------------------------------------------------------------------------

function hcn_loggia_availability_sync_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'Logger not available.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  // Use provider for config — respects new option keys + legacy fallback
  $lp = $hc['providers']['loggia'] ?? null;
  if (!$lp || !$lp->is_configured()) {
    wp_send_json_error(['message' => 'Loggia not configured.'], 400);
  }

  $c        = $lp->get_config();
  $page_id  = $c['page_id'];
  $locale   = $c['locale'];

  $property_id = sanitize_text_field($_POST['property_id'] ?? '');
  $post_id     = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
  $from        = sanitize_text_field($_POST['from'] ?? '');
  $to          = sanitize_text_field($_POST['to']   ?? '');

  if (!$property_id) {
    wp_send_json_error(['message' => 'Missing property_id.'], 400);
  }

  // Resolve post_id if not provided
  if (!$post_id) {
    $q = new WP_Query([
      'post_type'      => 'hcn_property',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => 1,
      'meta_query'     => [['key' => 'loggia_property_id', 'value' => $property_id]],
    ]);
    $post_id = !empty($q->posts[0]) ? (int)$q->posts[0] : 0;
  }

  if (!$post_id) {
    wp_send_json_error(['message' => 'No WP post found for this loggia_property_id.'], 404);
  }

  $client = $lp->make_client();
  if (!$client) {
    wp_send_json_error(['message' => 'Could not create Loggia client.'], 500);
  }

  if (!class_exists('HavenConnect_Loggia_Availability_Importer')) {
    hcn_require('providers/loggia/class-loggia-availability-importer.php');
  }
  if (!class_exists('HavenConnect_Loggia_Availability_Importer')) {
    wp_send_json_error(['message' => 'Loggia availability importer class not found.'], 500);
  }

  if (!$from) $from = gmdate('Y-m-d');
  if (!$to)   $to   = gmdate('Y-m-d', strtotime('+365 days'));

  $imp  = new HavenConnect_Loggia_Availability_Importer($logger);
  $rows = $imp->import_range($client, $page_id, $locale, $from, $to);

  wp_send_json_success([
    'message'     => "Loggia availability imported ({$rows} rows).",
    'rows'        => (int)$rows,
    'post_id'     => $post_id,
    'property_id' => $property_id,
    'from'        => $from,
    'to'          => $to,
  ]);
}

// ---------------------------------------------------------------------------
// LIVE LOG
// ---------------------------------------------------------------------------

function hcn_get_log_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'Logger not available.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];
  $path   = method_exists($logger, 'path') ? $logger->path() : null;

  if (!$path || !file_exists($path)) {
    wp_send_json_success(['chunk' => '', 'offset' => 0, 'size' => 0]);
  }

  $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
  $size   = (int)@filesize($path);

  if ($offset > $size) $offset = 0;

  $chunk = '';
  $fp = @fopen($path, 'rb');
  if ($fp) {
    if ($offset > 0) fseek($fp, $offset);
    $chunk = stream_get_contents($fp);
    fclose($fp);
  }

  wp_send_json_success([
    'chunk'  => is_string($chunk) ? $chunk : '',
    'offset' => $size,
    'size'   => $size,
  ]);
}

// ---------------------------------------------------------------------------
// LOGGIA CONNECTION TEST
// ---------------------------------------------------------------------------

function hcn_loggia_test_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'Logger not available.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  $lp = $hc['providers']['loggia'] ?? null;
  if (!$lp || !$lp->is_configured()) {
    wp_send_json_error(['message' => 'Loggia not configured (missing Base URL / API Key / Page ID).'], 400);
  }

  $c       = $lp->get_config();
  $client  = $lp->make_client();
  $page_id = $c['page_id'];
  $locale  = $c['locale'];

  $logger->log("Loggia test: calling list_properties_connections(page_id={$page_id}, locale={$locale}) …");
  $list = $client->list_properties_connections($page_id, $locale);

  if (!is_array($list)) {
    $logger->log("Loggia test: returned null/non-array.");
    $logger->save();
    wp_send_json_error(['message' => 'Loggia API call failed (no JSON returned).'], 502);
  }

  $first_id = null;
  $rows = $list['properties'] ?? null;
  if (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) {
    $first_id = $rows[0]['property_id'] ?? $rows[0]['id'] ?? null;
  }

  $logger->log("Loggia test: OK. First property_id=" . ($first_id ?: 'not detected'));
  $logger->save();

  wp_send_json_success([
    'message'            => 'Loggia connection OK.',
    'first_property_id'  => $first_id,
    'keys'               => array_keys($list),
  ]);
}

// ---------------------------------------------------------------------------
// PING
// ---------------------------------------------------------------------------

function hcn_ping_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  wp_send_json_success(['message' => 'pong', 'time' => time()]);
}