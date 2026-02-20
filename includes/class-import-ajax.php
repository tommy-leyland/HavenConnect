<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect AJAX Importer
 *
 * - Creates a property import queue
 * - Imports ONE property per AJAX request
 * - Uses the real importer, real API client, real logger
 * - Supports fallback if Featured list is empty
 * - Ensures images + logging work properly
 */

function hcn_ajax_get_settings(): array {
    $opts = get_option('havenconnect_settings', []);
    return [
        'apiKey'    => trim($opts['api_key'] ?? ''),
        'agencyUid' => trim($opts['agency_uid'] ?? ''),
    ];
}

function hcn_ajax_new_job_id(): string {
    return wp_generate_uuid4();
}

function hcn_ajax_job_key(string $job_id): string {
    return 'hcn_job_' . $job_id;
}

/**
 * START IMPORT — Build queue
 */
add_action('wp_ajax_hcn_import_start', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('hcn_import_nonce', 'nonce');

    // ALWAYS use the real objects created in havenconnect.php
    $logger   = $GLOBALS['havenconnect']['logger'];
    $api      = $GLOBALS['havenconnect']['api'];

    $settings  = hcn_ajax_get_settings();
    $apiKey    = $settings['apiKey'];
    $agencyUid = $settings['agencyUid'];

    if (empty($apiKey) || empty($agencyUid)) {
        wp_send_json_error(['message' => 'Missing API credentials.'], 400);
    }

    // 1) Try Featured list (v3.2 correct)
    $list     = $api->get_featured_list($apiKey, $agencyUid);
    $source   = 'featured';

    // 2) Fallback to all properties if featured is empty
    if (empty($list)) {
        $list   = $api->get_properties_by_agency($apiKey, $agencyUid);
        $source = 'all';
    }

    if (empty($list)) {
        wp_send_json_error(['message' => 'No properties returned for agency.'], 404);
    }

    // Build items
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
        wp_send_json_error(['message' => 'No valid properties found.'], 422);
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

    wp_send_json_success([
        'job_id' => $job_id,
        'total'  => $queue['total'],
        'source' => $source,
        'items'  => array_map(fn($i) => ['uid'=>$i['uid'],'name'=>$i['name']], $items),
        'message'=> 'Queue ready',
    ]);
});

/**
 * IMPORT SINGLE PROPERTY
 */
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

    $key   = hcn_ajax_job_key($job_id);
    $queue = get_transient($key);

    if (!$queue) {
        wp_send_json_error(['message' => 'Job not found or expired.'], 410);
    }
    if (!isset($queue['items'][$index])) {
        wp_send_json_error(['message' => 'Invalid queue index.'], 400);
    }

    // Use the REAL importer + logger + api
    $logger   = $GLOBALS['havenconnect']['logger'];
    $api      = $GLOBALS['havenconnect']['api'];
    $importer = $GLOBALS['havenconnect']['importer'];

    $settings = hcn_ajax_get_settings();
    $apiKey   = $settings['apiKey'];

    if (!$apiKey) {
        wp_send_json_error(['message' => 'Missing API key'], 400);
    }

    $p   = $queue['items'][$index]['payload'];
    $uid = $queue['items'][$index]['uid'];
    $nm  = $queue['items'][$index]['name'];

    try {
        // Import using the real importer singleton
        $post_id = $importer->import_property_from_featured($apiKey, $p);

        $queue['done'] = max($queue['done'], $index + 1);
        set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

        $logger->save(); // ← CRITICAL: flush writes so UI can see logs and photos errors surface

        wp_send_json_success([
            'index'    => $index,
            'uid'      => $uid,
            'name'     => $nm,
            'post_id'  => $post_id,
            'done'     => $queue['done'],
            'total'    => $queue['total'],
            'message'  => "Imported {$nm}"
        ]);

    } catch (Throwable $e) {
        $logger->log("ERROR importing {$uid}: " . $e->getMessage());
        wp_send_json_error([
            'index'   => $index,
            'uid'     => $uid,
            'name'    => $nm,
            'message' => $e->getMessage(),
        ], 500);
    }
});


/**
 * FINISH — clear job
 */
add_action('wp_ajax_hcn_import_finish', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('hcn_import_nonce', 'nonce');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    if (!$job_id) {
        wp_send_json_error(['message' => 'Missing job_id'], 400);
    }

    delete_transient(hcn_ajax_job_key($job_id));
    $logger->save();
    wp_send_json_success(['message' => 'Import complete']);
});