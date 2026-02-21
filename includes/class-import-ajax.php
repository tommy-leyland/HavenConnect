<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect AJAX Importer (init-hook version, final)
 *
 * - Handlers are registered on init (after bootstrap)
 * - Uses the real singletons from $GLOBALS['havenconnect']
 * - Clears log on start, flushes on each single import and on finish
 * - Falls back Featured -> All properties
 * - No stray output that could corrupt JSON
 */

/** Settings helper */
function hcn_ajax_get_settings(): array {
    $opts = get_option('havenconnect_settings', []);
    return [
        'apiKey'    => trim($opts['api_key'] ?? ''),
        'agencyUid' => trim($opts['agency_uid'] ?? ''),
    ];
}
function hcn_ajax_new_job_id(): string { return wp_generate_uuid4(); }
function hcn_ajax_job_key(string $job_id): string { return 'hcn_job_' . $job_id; }

/** Register AJAX handlers only AFTER init */
add_action('init', function () {
    add_action('wp_ajax_hcn_import_start',  'hcn_import_start_handler');
    add_action('wp_ajax_hcn_import_single', 'hcn_import_single_handler');
    add_action('wp_ajax_hcn_import_finish', 'hcn_import_finish_handler');
});

/** START — build queue (clears log, builds from Featured -> All) */
function hcn_import_start_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('hcn_import_nonce', 'nonce');

    // Pull shared singletons
    $hc = $GLOBALS['havenconnect'] ?? null;
    if (!is_array($hc) || empty($hc['logger']) || empty($hc['api'])) {
        // Hard fail with clear error so we don't silently continue
        wp_send_json_error(['message' => 'HavenConnect singletons not initialized yet.'], 500);
    }
    /** @var HavenConnect_Logger $logger */
    $logger = $hc['logger'];
    /** @var HavenConnect_Api_Client $api */
    $api    = $hc['api'];

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

    // 1) Try v3.2 Featured list
    $list   = $api->get_featured_list($apiKey, $agencyUid);
    $source = 'featured';

    // 2) Fallback to full property list
    if (empty($list) && method_exists($api, 'get_properties_by_agency')) {
        $list   = $api->get_properties_by_agency($apiKey, $agencyUid);
        $source = 'all';
    }

    if (empty($list)) {
        $logger->log('No properties available (Featured and All were empty).');
        $logger->save();
        wp_send_json_error(['message' => 'No properties available for this agency.'], 404);
    }

    // Build queue
    $items = [];
    foreach ($list as $p) {
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
    $queue  = [
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
        'job_id' => $job_id,
        'total'  => $queue['total'],
        'source' => $source,
        'items'  => array_map(fn($i) => ['uid'=>$i['uid'],'name'=>$i['name']], $items),
        'message'=> 'Queue created successfully.'
    ]);
}

/** SINGLE — import one property (flush log every item) */
function hcn_import_single_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('hcn_import_nonce', 'nonce');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    $index  = isset($_POST['index']) ? (int)$_POST['index'] : -1;

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
    $logger   = $hc['logger'];
    /** @var HavenConnect_Property_Importer $importer */
    $importer = $hc['importer'];

    $settings = hcn_ajax_get_settings();
    $apiKey   = $settings['apiKey'];
    if (!$apiKey) {
        $logger->log('Missing API key.');
        $logger->save();
        wp_send_json_error(['message' => 'Missing API key'], 400);
    }

    $p    = $queue['items'][$index]['payload'];
    $uid  = $queue['items'][$index]['uid'];
    $name = $queue['items'][$index]['name'];

    try {
        $logger->log("Importing {$name} ({$uid}) …");

        $post_id = $importer->import_property_from_featured($apiKey, $p);

        $queue['done'] = max($queue['done'], $index + 1);
        set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

        $logger->log("Imported {$name} (post_id={$post_id})");
        $logger->save();

        wp_send_json_success([
            'index'    => $index,
            'uid'      => $uid,
            'name'     => $name,
            'post_id'  => $post_id,
            'done'     => $queue['done'],
            'total'    => $queue['total'],
            'message'  => "Imported {$name}"
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

/** FINISH — cleanup + flush */
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