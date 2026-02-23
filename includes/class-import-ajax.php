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
 * - Supports single-property testing by passing POST property_uid (Hostfully only).
 * - Supports providers: hostfully | loggia | both
 * - No stray output that could corrupt JSON.
 */

if (!defined('ABSPATH')) exit;

/** Settings helper */
function hcn_ajax_get_settings(): array {
  $opts = get_option('havenconnect_settings', []);
  return [
    'apiKey' => trim($opts['api_key'] ?? ''),
    'agencyUid' => trim($opts['agency_uid'] ?? ''),

    'loggiaBaseUrl' => trim($opts['loggia_base_url'] ?? ''),
    'loggiaApiKey'  => trim($opts['loggia_api_key'] ?? ''),
    'loggiaPageId'  => trim($opts['loggia_page_id'] ?? ''),
    'loggiaLocale'  => trim($opts['loggia_locale'] ?? 'en'),
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
  add_action('wp_ajax_hcn_loggia_test', 'hcn_loggia_test_handler');
  add_action('wp_ajax_hcn_ping', 'hcn_ping_handler');
});

/**
 * START — build queue
 *
 * POST params:
 * - mode: 'all' (default) or 'featured' (Hostfully only)
 * - provider: 'hostfully' (default) | 'loggia' | 'both'
 * - property_uid: optional, if set builds a single-item queue (Hostfully only)
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

  // Purge orphan availability rows before building a new queue (failsafe)
  if (!empty($GLOBALS['havenconnect']['availability_table'])
      && method_exists($GLOBALS['havenconnect']['availability_table'], 'purge_orphan_rows')) {
    $n = $GLOBALS['havenconnect']['availability_table']->purge_orphan_rows();
    $logger->log("Availability: purged {$n} orphan rows.");
  }

  $settings  = hcn_ajax_get_settings();
  $locale = $settings['loggiaLocale'] ?? 'en';

  $mode       = sanitize_text_field($_POST['mode'] ?? 'all');
  $provider   = sanitize_text_field($_POST['provider'] ?? 'hostfully'); // hostfully|loggia|both
  $single_uid = sanitize_text_field($_POST['property_uid'] ?? '');

  // Normalize provider
  if (!in_array($provider, ['hostfully', 'loggia', 'both'], true)) {
    $provider = 'hostfully';
  }

  $items  = [];
  $source = $provider;

  /**
   * HOSTFULLY QUEUE
   * - Only if provider=hostfully|both
   * - Supports single_uid and mode featured/all
   */
  if ($provider === 'hostfully' || $provider === 'both') {
    $apiKey    = $settings['apiKey'];
    $agencyUid = $settings['agencyUid'];

    if (!$apiKey || !$agencyUid) {
      $logger->log('Hostfully: missing API credentials; skipping Hostfully queue build.');
    } else {
      $list = [];
      $hostfully_source = 'all';

      // Single property mode (test import) — Hostfully only
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
          $hostfully_source = 'single';
        }
      } else {
        // Default: ALL properties (clean baseline)
        if ($mode === 'featured' && method_exists($api, 'get_featured_list')) {
          $list = $api->get_featured_list($apiKey, $agencyUid);
          $hostfully_source = 'featured';
        } else {
          if (method_exists($api, 'get_properties_by_agency')) {
            $list = $api->get_properties_by_agency($apiKey, $agencyUid);
            $hostfully_source = 'all';
          }
        }
      }

      if (empty($list)) {
        $logger->log("Hostfully: no properties available for mode={$hostfully_source}.");
      } else {
        foreach ($list as $p) {
          if (!is_array($p)) continue;

          $uid  = $p['uid'] ?? ($p['UID'] ?? null);
          $name = $p['name'] ?? ($p['Name'] ?? 'Untitled');

          if ($uid) {
            $items[] = [
              'provider'    => 'hostfully',
              'external_id' => (string)$uid,

              // Backwards-compat keys (your UI returns uid/name)
              'uid'         => (string)$uid,
              'name'        => $name,

              // Keep payload for existing Hostfully importer
              'payload'     => $p,
            ];
          }
        }
        $logger->log("Hostfully: queued " . count(array_filter($items, fn($i) => ($i['provider'] ?? '') === 'hostfully')) . " items.");
      }

      // If provider was specifically hostfully, keep source close to old behaviour
      if ($provider === 'hostfully') {
        $source = $hostfully_source;
      } elseif ($provider === 'both') {
        $source = 'both';
      }
    }
  }

  /**
   * LOGGIA QUEUE
   * - Only if provider=loggia|both
   */
  if ($provider === 'loggia' || $provider === 'both') {
    $base_url = $settings['loggiaBaseUrl'];
    $api_key  = $settings['loggiaApiKey'];
    $page_id  = $settings['loggiaPageId'];

    if (!$base_url || !$api_key || !$page_id) {
      $logger->log('Loggia: missing base_url/api_key/page_id; skipping Loggia queue build.');
    } else {
      $loggia_importer = $GLOBALS['havenconnect']['loggia_importer'] ?? null;
      if (!$loggia_importer || !is_object($loggia_importer) || !method_exists($loggia_importer, 'list_property_ids')) {
        $logger->log('Loggia: loggia_importer not initialized; skipping Loggia queue build.');
      } else {
        $client_path = defined('HCN_PATH')
          ? HCN_PATH . 'includes/providers/loggia/class-loggia-client.php'
          : (plugin_dir_path(__FILE__) . 'providers/loggia/class-loggia-client.php');

        if (file_exists($client_path)) {
          require_once $client_path;
        }

        if (!class_exists('HavenConnect_Loggia_Client')) {
          $logger->log('Loggia: HavenConnect_Loggia_Client class not found; skipping.');
        } else {
          $client = new HavenConnect_Loggia_Client($base_url, $api_key, $logger);

          // Single property mode for Loggia (test import)
          if ($provider === 'loggia' && $single_uid) {
            $ids = [(string)$single_uid];
            $logger->log("Loggia: single property mode enabled (property_id={$single_uid}).");
          } else {
            $ids = $loggia_importer->list_property_ids($client, $page_id, $locale);
          }

          if (empty($ids)) {
            $logger->log('Loggia: no property ids returned.');
          } else {
            foreach ($ids as $id) {
              $id = (string)$id;
              if (!$id) continue;

              $items[] = [
                'provider'    => 'loggia',
                'external_id' => $id,

                // Backwards-compat keys (your UI expects uid/name)
                'uid'         => $id,
                'name'        => 'Loggia ' . $id,

                // No payload needed for Loggia (fetch per property)
                'payload'     => [],
              ];
            }
            $logger->log('Loggia: queued ' . count($ids) . ' items.');
          }
        }
      }
    }

    if ($provider === 'loggia') {
      $source = 'loggia';
    } elseif ($provider === 'both') {
      $source = 'both';
    }
  }

  if (empty($items)) {
    $logger->log("No properties queued for provider={$provider}.");
    $logger->save();
    wp_send_json_error(['message' => 'No properties available (or credentials missing) for the selected provider(s).'], 404);
  }

  $job_id = hcn_ajax_new_job_id();
  $key    = hcn_ajax_job_key($job_id);

  $queue = [
    'created_at' => time(),
    'total'      => count($items),
    'done'       => 0,
    'source'     => $source,
    'provider'   => $provider,
    'items'      => $items,
  ];

  set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

  $logger->log("Queue created ({$source}) with {$queue['total']} items.");
  $logger->save();

  wp_send_json_success([
    'job_id'  => $job_id,
    'total'   => $queue['total'],
    'source'  => $source,
    'provider'=> $provider,
    // keep UI shape stable: uid/name (and add provider if you want to show it)
    'items'   => array_map(fn($i) => [
      'uid'      => $i['uid'] ?? ($i['external_id'] ?? ''),
      'name'     => $i['name'] ?? '',
      'provider' => $i['provider'] ?? 'hostfully',
    ], $items),
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
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'HavenConnect singletons not initialized.'], 500);
  }

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  $settings = hcn_ajax_get_settings();

  $item      = $queue['items'][$index];
  $provider  = $item['provider'] ?? 'hostfully';
  $external  = (string)($item['external_id'] ?? ($item['uid'] ?? ''));
  $uid       = (string)($item['uid'] ?? $external);
  $name      = (string)($item['name'] ?? $external);
  $p         = is_array($item['payload'] ?? null) ? $item['payload'] : [];

  try {
    $logger->log("Importing {$name} ({$provider}:{$external}) …");
	$logger->save(); // <-- so we still see this line even if something fatals later

    $post_id = 0;

    if ($provider === 'hostfully') {
      if (empty($hc['importer'])) {
        wp_send_json_error(['message' => 'Hostfully importer not initialized.'], 500);
      }

      /** @var HavenConnect_Property_Importer $importer */
      $importer = $hc['importer'];

      $apiKey = $settings['apiKey'];
      if (!$apiKey) {
        $logger->log('Missing Hostfully API key.');
        $logger->save();
        wp_send_json_error(['message' => 'Missing Hostfully API key'], 400);
      }

      // Reuse existing importer method; it accepts payload arrays with uid/name present.
      $post_id = $importer->import_property_from_featured($apiKey, $p);

    } elseif ($provider === 'loggia') {
      $loggia_importer = $GLOBALS['havenconnect']['loggia_importer'] ?? null;
      if (!$loggia_importer || !is_object($loggia_importer) || !method_exists($loggia_importer, 'import_one')) {
        wp_send_json_error(['message' => 'Loggia importer not initialized.'], 500);
      }

      $base_url = $settings['loggiaBaseUrl'];
      $api_key  = $settings['loggiaApiKey'];
      $page_id  = $settings['loggiaPageId'];
      $locale   = $settings['loggiaLocale'] ?: 'en';

      if (!$base_url || !$api_key || !$page_id) {
        $logger->log('Missing Loggia settings (base_url/api_key/page_id).');
        $logger->save();
        wp_send_json_error(['message' => 'Missing Loggia settings'], 400);
      }

      $client_path = defined('HCN_PATH')
        ? HCN_PATH . 'includes/providers/loggia/class-loggia-client.php'
        : (plugin_dir_path(__FILE__) . 'providers/loggia/class-loggia-client.php');

      if (file_exists($client_path)) {
        require_once $client_path;
      }

      if (!class_exists('HavenConnect_Loggia_Client')) {
        $logger->log('Loggia client class not found.');
        $logger->save();
        wp_send_json_error(['message' => 'Loggia client class not found'], 500);
      }

      $client = new HavenConnect_Loggia_Client($base_url, $api_key, $logger);
		$required = ['list_properties_connections','get_summary','get_content','get_descriptions','get_media','get_features_by_group','get_location'];
			foreach ($required as $m) {
			if (!method_exists($client, $m)) {
				$logger->log("Loggia ERROR: client missing method {$m}. Update includes/providers/loggia/class-loggia-client.php");
				$logger->save();
				wp_send_json_error(['message' => "Loggia client missing method: {$m}"], 500);
			}
		}
      $post_id = (int)$loggia_importer->import_one($client, $external, $page_id, $locale);

    } else {
      wp_send_json_error(['message' => 'Unknown provider: ' . $provider], 400);
    }

    $queue['done'] = max((int)$queue['done'], $index + 1);
    set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

    $logger->log("Imported {$name} (provider={$provider}, post_id={$post_id})");
    $logger->save();

    wp_send_json_success([
      'index'    => $index,
      'uid'      => $uid,
      'name'     => $name,
      'provider' => $provider,
      'post_id'  => $post_id,
      'done'     => $queue['done'],
      'total'    => $queue['total'],
      'message'  => "Imported {$name}",
    ]);

  } catch (Throwable $e) {
    $logger->log("ERROR importing {$provider}:{$external}: " . $e->getMessage());
    $logger->save();
    $logger->log($e->getTraceAsString());
    $logger->save();

    wp_send_json_error([
      'index'    => $index,
      'uid'      => $uid,
      'name'     => $name,
      'provider' => $provider,
      'message'  => $e->getMessage(),
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
 * - offset: optional int, return only from offset (default 0)
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

  $path = method_exists($logger, 'path') ? $logger->path() : null;
  if (!$path || !file_exists($path)) {
    wp_send_json_success(['chunk' => '', 'offset' => 0, 'size' => 0]);
  }

  $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
  $size   = (int)@filesize($path);

  // If log was cleared/truncated, reset offset
  if ($offset > $size) $offset = 0;

  $chunk = '';
  $fp = @fopen($path, 'rb');
  if ($fp) {
    if ($offset > 0) {
      fseek($fp, $offset);
    }
    $chunk = stream_get_contents($fp);
    fclose($fp);
  }

  wp_send_json_success([
    'chunk'  => is_string($chunk) ? $chunk : '',
    'offset' => $size,
    'size'   => $size,
  ]);
}

function hcn_loggia_test_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  $hc = $GLOBALS['havenconnect'] ?? null;
  if (!is_array($hc) || empty($hc['logger'])) {
    wp_send_json_error(['message' => 'Logger not available.'], 500);
  }

  header('Content-Type: application/json; charset=' . get_option('blog_charset'));
  header('X-Content-Type-Options: nosniff');
  nocache_headers();

  /** @var HavenConnect_Logger $logger */
  $logger = $hc['logger'];

  $settings = hcn_ajax_get_settings();
  $base_url = $settings['loggiaBaseUrl'] ?? '';
  $api_key  = $settings['loggiaApiKey'] ?? '';
  $page_id  = $settings['loggiaPageId'] ?? '';
  $locale   = $settings['loggiaLocale'] ?? 'en';

  if (!$base_url || !$api_key || !$page_id) {
    wp_send_json_error(['message' => 'Loggia not configured (missing Base URL / API Key / Page ID).'], 400);
  }

  // Load client class
  $client_path = defined('HCN_PATH')
    ? HCN_PATH . 'includes/providers/loggia/class-loggia-client.php'
    : (plugin_dir_path(__FILE__) . 'providers/loggia/class-loggia-client.php');

  if (file_exists($client_path)) {
    require_once $client_path;
  }

  if (!class_exists('HavenConnect_Loggia_Client')) {
    wp_send_json_error(['message' => 'Loggia client class not found.'], 500);
  }

  $client = new HavenConnect_Loggia_Client($base_url, $api_key, $logger);

  $logger->log("Loggia test: calling list_properties_connections(page_id={$page_id}, locale={$locale}) …");
  $list = $client->list_properties_connections($page_id, $locale);

  if (!is_array($list)) {
    $logger->log("Loggia test: list_properties_connections returned null/non-array.");
    $logger->save();
    wp_send_json_error(['message' => 'Loggia API call failed (no JSON returned).'], 502);
  }

  // Response shape: { properties: [ ... ] }
  $first_id = null;
  $rows = $list['properties'] ?? null;
  if (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) {
    $first_id = $rows[0]['property_id'] ?? $rows[0]['id'] ?? null;
  }

  $logger->log("Loggia test: list OK. First property_id=" . ($first_id ? $first_id : 'not detected'));
  $logger->save();

  wp_send_json_success([
    'message' => 'Loggia connection OK (list endpoint responded).',
    'first_property_id' => $first_id,
    'keys' => array_keys($list),
  ]);
}

function hcn_ping_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
  }
  check_ajax_referer('hcn_import_nonce', 'nonce');

  header('Content-Type: application/json; charset=' . get_option('blog_charset'));
  header('X-Content-Type-Options: nosniff');
  nocache_headers();

  wp_send_json_success([
    'message' => 'pong',
    'time' => time(),
  ]);
}