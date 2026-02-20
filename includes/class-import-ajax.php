<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect AJAX Importer
 *
 * IMPORTANT:
 * This file is now wrapped in an init hook so that AJAX handlers
 * are registered ONLY AFTER the plugin bootstrap has created:
 *
 *   $GLOBALS['havenconnect']['logger']
 *   $GLOBALS['havenconnect']['api']
 *   $GLOBALS['havenconnect']['importer']
 *   $GLOBALS['havenconnect']['photos']
 *   $GLOBALS['havenconnect']['tax']
 *
 * Without this, AJAX would run too early and break logs + images.
 */

/**
 * Get API + Agency UID from settings
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
 * REGISTER AJAX ACTIONS — only AFTER init
 */
add_action('init', function () {
    add_action('wp_ajax_hcn_import_start', 'hcn_import_start_handler');
    add_action('wp_ajax_hcn_import_single', 'hcn_import_single_handler');
    add_action('wp_ajax_hcn_import_finish', 'hcn_import_finish_handler');
});



/**
 * START IMPORT — creates queue
 */
function hcn_import_start_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('hcn_import_nonce', 'nonce');

    // USE REAL SINGLETONS
    $logger   = $GLOBALS['havenconnect']['logger'];
    $api      = $GLOBALS['havenconnect']['api'];

    $settings  = hcn_ajax_get_settings();
    $apiKey    = $settings['apiKey'];
    $agencyUid = $settings['agencyUid'];

    if (!$apiKey || !$agencyUid) {
        wp_send_json_error(['message' => 'Missing API credentials.'], 400);
    }

    // 1) Try v3.2 Featured
    $list   = $api->get_featured_list($apiKey, $agencyUid);
    $source = 'featured';

    // 2) Fallback to full property list
    if (empty($list)) {
        $list   = $api->get_properties_by_agency($apiKey, $agencyUid);
        $source = 'all';
    }

    if (empty($list)) {
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

    wp_send_json_success([
        'job_id' => $job_id,
        'total'  => $queue['total'],
        'source' => $source,
        'items'  => array_map(fn($i) => ['uid'=>$i['uid'],'name'=>$i['name']], $items),
        'message'=> 'Queue created successfully.'
    ]);
}



/**
 * IMPORT 1 PROPERTY
 */
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

    // REAL SINGLETONS
    $logger   = $GLOBALS['havenconnect']['logger'];
    $api      = $GLOBALS['havenconnect']['api'];
    $importer = $GLOBALS['havenconnect']['importer'];

    $settings = hcn_ajax_get_settings();
    $apiKey   = $settings['apiKey'];

    if (!$apiKey) {
        wp_send_json_error(['message' => 'Missing API key'], 400);
    }

    $p     = $queue['items'][$index]['payload'];
    $uid   = $queue['items'][$index]['uid'];
    $name  = $queue['items'][$index]['name'];

    try {

        // Import using the REAL importer singleton
        $post_id = $importer->import_property_from_featured($apiKey, $p);

        // Update queue
        $queue['done'] = max($queue['done'], $index + 1);
        set_transient($key, $queue, 2 * HOUR_IN_SECONDS);

        // Flush logs so UI can see them
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



/**
 * FINISH IMPORT — cleanup
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

    $key = hcn_ajax_job_key($job_id);
    delete_transient($key);

    // IMPORTANT: flush logs on finish
    $logger = $GLOBALS['havenconnect']['logger'];
    $logger->save();

    wp_send_json_success(['message' => 'Import completed']);
}