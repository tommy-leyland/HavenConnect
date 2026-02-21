<?php
/**
 * HavenConnect AJAX Import Handlers
 *
 * - hcn_import_start  : builds a transient queue
 * - hcn_import_single : imports one item from queue
 * - hcn_import_finish : cleans up queue
 *
 * Notes:
 * - Default queue mode is ALL properties (not featured).
 * - Supports single-property testing by passing POST property_uid.
 * - No stray output that could corrupt JSON.
 */

if (!defined('ABSPATH')) exit;

/** Settings helper */
function hcn_ajax_get_settings(): array {
  $opts = get_option('havenconnect_settings', []);
  return [
    'apiKey'     => trim($opts['api_key'] ?? ''),
    'agencyUid'  => trim($opts['agency_uid'] ?? ''),
  ];
}

function hcn_ajax_new_job_id(): string {
  return wp_generate_uuid4();
}

function hcn_ajax_job_key(string $job_id): string {
  return 'hcn_job_' . $job_id;
}

/** Register AJAX handlers only AFTER init */
add_action('init', function () {
  add_action('wp_ajax_hcn_import_start',  'hcn_import_start_handler');
  add_action('wp_ajax_hcn_import_single', 'hcn_import_single_handler');
  add_action('wp_ajax_hcn_import_finish', 'hcn_import_finish_handler');
  add_action('wp_ajax_hcn_get_log', 'hcn_get_log_handler');
});

/**
 * START — build queue
 *
 * POST params:
 * - mode: 'all' (default) or 'featured'
 * - property_uid: optional, if set builds a single-item queue
 */
function hcn_import_start_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger']) || empty($hc['api'])) {
    wp_send_json_error(['message' => 'HavenConnect singletons not initialized yet.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];
  /** @var HavenConnect_Api_Client $api */
  $api = $hc['api'];

  // Clear previous log so admin panel shows a fresh run
  if (method_exists($logger, 'clear')) {
    $logger->clear();
  }

  $settings  = hcn_ajax_get_settings();
  $apiKey    = $settings['apiKey'];
  $agencyUid = $settings['agencyUid'];

  if (!$apiKey || !$agencyUid) {
    $logger->log('Missing API credentials for AJAX start.');
    $logger->save();
    wp_send_json_error(['message' => 'Missing API credentials.'], 400);
  }

  $mode = sanitize_text_field($_POST['mode'] ?? 'all');
  $single_uid = sanitize_text_field($_POST['property_uid'] ?? '');

  $list = [];
  $source = 'all';

  // Single property mode (test import)
  if ($single_uid) {
    $details = $api->get_property_details($apiKey, $single_uid);
    $p = [];

    if (is_array($details)) {
      $p = isset($details['property']) && is_array($details['property'])
        ? $details['property']
        : $details;
    }

    if (!empty($p)) {
      $list = [$p];
      $source = 'single';
    }
  } else {
    // Default: ALL properties (clean baseline)
    if ($mode === 'featured' && method_exists($api, 'get_featured_list')) {
      $list = $api->get_featured_list($apiKey, $agencyUid);
      $source = 'featured';
    } else {
      if (method_exists($api, 'get_properties_by_agency')) {
        $list = $api->get_properties_by_agency($apiKey, $agencyUid);
        $source = 'all';
      }
    }
  }

  if (empty($list)) {
    $logger->log("No properties available for mode={$source}.");
    $logger->save();
    wp_send_json_error(['message' => 'No properties available for this agency/mode.'], 404);
  }

  // Build queue items
  $items = [];
  foreach ($list as $p) {
    if (!is_array($p)) continue;

    $uid  = $p['uid'] ?? ($p['UID'] ?? null);
    $name = $p['name'] ?? ($p['Name'] ?? 'Untitled');

    if ($uid) {
      $items[] = [
        'uid'     => $uid,
        'name'    => $name,
        'payload' => $p,
      ];
    }
  }

  if (empty($items)) {
    $logger->log('Properties returned but contained no valid UID.');
    $logger->save();
    wp_send_json_error(['message' => 'Properties returned but no UIDs found.'], 422);
  }

  $job_id = hcn_ajax_new_job_id();
  $key    = hcn_ajax_job_key($job_id);

  $queue = [
    'created_at' => time(),
    'total'      => count($items),
    'done'       => 0,
    'source'     => $source,
    'items'      => $items,
  ];

  set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

  $logger->log("Queue created ({$source}) with {$queue['total']} items.");
  $logger->save();

  wp_send_json_success([
    'job_id'  => $job_id,
    'total'   => $queue['total'],
    'source'  => $source,
    'items'   => array_map(fn($i) => ['uid' => $i['uid'], 'name' => $i['name']], $items),
    'message' => 'Queue created successfully.',
  ]);
}

/**
 * SINGLE — import one property from queue
 *
 * POST params:
 * - job_id
 * - index
 */
function hcn_import_single_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $job_id = sanitize_text_field($_POST['job_id'] ?? '');
  $index  = isset($_POST['index']) ? (int) $_POST['index'] : -1;

  if (!$job_id || $index < 0) {
    wp_send_json_error(['message' => 'Missing job_id or index'], 400);
  }

  $key   = hcn_ajax_job_key($job_id);
  $queue = get_transient($key);

  if (!$queue) {
    wp_send_json_error(['message' => 'Queue expired or missing.'], 410);
  }
  if (!isset($queue['items'][$index])) {
    wp_send_json_error(['message' => 'Invalid property index.'], 400);
  }

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger']) || empty($hc['importer'])) {
    wp_send_json_error(['message' => 'HavenConnect singletons not initialized.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];
  /** @var HavenConnect_Property_Importer $importer */
  $importer = $hc['importer'];

  $settings = hcn_ajax_get_settings();
  $apiKey   = $settings['apiKey'];

  if (!$apiKey) {
    $logger->log('Missing API key.');
    $logger->save();
    wp_send_json_error(['message' => 'Missing API key'], 400);
  }

  $item = $queue['items'][$index];
  $p    = $item['payload'];
  $uid  = $item['uid'];
  $name = $item['name'];

  try {
    $logger->log("Importing {$name} ({$uid}) …");

    // Reuse existing importer method; it accepts payload arrays with uid/name present.
    $post_id = $importer->import_property_from_featured($apiKey, $p);

    $queue['done'] = max((int)$queue['done'], $index + 1);
    set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

    $logger->log("Imported {$name} (post_id={$post_id})");
    $logger->save();

    wp_send_json_success([
      'index'   => $index,
      'uid'     => $uid,
      'name'    => $name,
      'post_id' => $post_id,
      'done'    => $queue['done'],
      'total'   => $queue['total'],
      'message' => "Imported {$name}",
    ]);

  } catch (Throwable $e) {
    $logger->log("ERROR importing {$uid}: " . $e->getMessage());
    $logger->save();

    wp_send_json_error([
      'index'   => $index,
      'uid'     => $uid,
      'name'    => $name,
      'message' => $e->getMessage(),
    ], 500);
  }
}

/**
 * FINISH — cleanup queue + flush
 *
 * POST params:
 * - job_id
 */
function hcn_import_finish_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $job_id = sanitize_text_field($_POST['job_id'] ?? '');
  if (!$job_id) {
    wp_send_json_error(['message' => 'Missing job_id'], 400);
  }

  delete_transient(hcn_ajax_job_key($job_id));

  if (!empty($GLOBALS['havenconnect']['logger'])) {
    $GLOBALS['havenconnect']['logger']->log('Import finished.');
    $GLOBALS['havenconnect']['logger']->save();
  }

  wp_send_json_success(['message' => 'Import completed']);
}

/**
 * LIVE LOG — fetch current log text for polling
 *
 * POST params:
 * - tail: optional int, return only last N characters (default 12000)
 */
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

  $tail = isset($_POST['tail']) ? max(1000, (int) $_POST['tail']) : 12000;

  $text = '';
  if (method_exists($logger, 'get')) {
    $text = (string) $logger->get();
  }

  // Return last N chars (keeps payload small)
  if (strlen($text) > $tail) {
    $text = substr($text, -$tail);
  }

  wp_send_json_success([
    'log' => $text,
  ]);
}