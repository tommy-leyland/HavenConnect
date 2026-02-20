<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect AJAX Importer
 * - Batches a queue of properties pulled from Hostfully
 * - Imports ONE property per AJAX request (safe for timeouts)
 * - Designed so Cron can call the SAME importer methods later
 *
 * Endpoints:
 *   wp_ajax_hcn_import_start
 *   wp_ajax_hcn_import_single
 *   wp_ajax_hcn_import_finish
 *
 * Queue storage:
 *   transient: hcn_job_{job_id}  (array: ['created_at','total','done','items'=>[ ['uid','name','payload']... ]])
 *
 * Security:
 *   - Nonce: 'hcn_import_nonce'
 *   - Capability: 'manage_options' (adjust if you want editors to run imports)
 */

/** Utilities */
function hcn_importer_get_settings(): array {

    // This is where your settings actually live.
    $opts = get_option('havenconnect_settings', []);

    return [
        'apiKey'    => isset($opts['api_key'])    ? trim($opts['api_key'])    : '',
        'agencyUid' => isset($opts['agency_uid']) ? trim($opts['agency_uid']) : '',
    ];
}

function hcn_new_job_id(): string {
    return wp_generate_uuid4();
}

function hcn_job_key(string $job_id): string {
    return 'hcn_job_' . $job_id;
}

/** START: prepare queue from Hostfully (Featured list) */
add_action('wp_ajax_hcn_import_start', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('hcn_import_nonce', 'nonce');

    $settings  = hcn_importer_get_settings();
    $apiKey    = $settings['apiKey'];
    $agencyUid = $settings['agencyUid'];

    if (empty($apiKey) || empty($agencyUid)) {
        wp_send_json_error(['message' => 'Missing API credentials.'], 400);
    }

    // Build the queue using API client directly (not through importer)
    $logger = $GLOBALS['havenconnect']['logger'] ?? null;
    $api    = new HavenConnect_Api_Client($logger);

    $list = $api->get_featured_list($apiKey, $agencyUid); // returns array of property objects

    if (!is_array($list) || empty($list)) {
        wp_send_json_error(['message' => 'No properties found for Featured list.']);
    }

    // Minify payload for queue, but keep the property object so we can import without refetching
    $items = [];
    foreach ($list as $p) {
        $uid  = $p['uid'] ?? ($p['UID'] ?? null);
        $name = $p['name'] ?? ($p['Name'] ?? 'Untitled');
        if ($uid) {
            $items[] = [
                'uid'     => $uid,
                'name'    => $name,
                'payload' => $p, // keep full object so we can import without another GET /properties/{uid}
            ];
        }
    }

    if (empty($items)) {
        wp_send_json_error(['message' => 'No valid property UIDs in Featured list.']);
    }

    $job_id = hcn_new_job_id();
    $key    = hcn_job_key($job_id);
    $queue  = [
        'created_at' => time(),
        'total'      => count($items),
        'done'       => 0,
        'apiKey'     => null,     // do not store secrets; read each request from options
        'agencyUid'  => null,     // do not store secrets
        'items'      => $items,
    ];

    // Persist for 2 hours
    set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

    wp_send_json_success([
        'job_id' => $job_id,
        'total'  => $queue['total'],
        'items'  => array_map(fn($it) => ['uid' => $it['uid'], 'name' => $it['name']], $items),
        'message'=> 'Queue created',
    ]);
});

/** SINGLE: import one property by index (0-based) */
add_action('wp_ajax_hcn_import_single', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('hcn_import_nonce', 'nonce');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    $index  = isset($_POST['index']) ? (int)$_POST['index'] : -1;

    if (!$job_id || $index < 0) {
        wp_send_json_error(['message' => 'Missing job_id or index'], 400);
    }

    $key   = hcn_job_key($job_id);
    $queue = get_transient($key);

    if (!$queue || !is_array($queue)) {
        wp_send_json_error(['message' => 'Job not found or expired.'], 410);
    }

    if (!isset($queue['items'][$index])) {
        wp_send_json_error(['message' => 'Queue index out of range.'], 400);
    }

    $settings = hcn_importer_get_settings();
    $apiKey    = $settings['apiKey'];
    if (empty($apiKey)) {
        wp_send_json_error(['message' => 'API key missing in settings.'], 400);
    }

    $importer = $GLOBALS['havenconnect']['importer'] ?? null;
    if (!$importer) {
        wp_send_json_error(['message' => 'Importer not available.'], 500);
    }

    $p   = $queue['items'][$index]['payload'];
    $uid = $queue['items'][$index]['uid'];
    $nm  = $queue['items'][$index]['name'];

    try {
        // Import just this one property using the new per-property method (see Section B)
        $post_id = $importer->import_property_from_featured($apiKey, $p);
        $queue['done'] = max($queue['done'], $index + 1);
        set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'index'    => $index,
            'uid'      => $uid,
            'name'     => $nm,
            'post_id'  => $post_id,
            'done'     => $queue['done'],
            'total'    => $queue['total'],
            'message'  => "Imported {$nm}",
        ]);

    } catch (Throwable $e) {
        wp_send_json_error([
            'index'   => $index,
            'uid'     => $uid,
            'name'    => $nm,
            'message' => 'Import failed: ' . $e->getMessage(),
        ], 500);
    }
});

/** FINISH: cleanup job */
add_action('wp_ajax_hcn_import_finish', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('hcn_import_nonce', 'nonce');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    if (!$job_id) {
        wp_send_json_error(['message' => 'Missing job_id'], 400);
    }

    delete_transient(hcn_job_key($job_id));
    wp_send_json_success(['message' => 'Queue cleared.']);
});