<?php
if (!defined('ABSPATH')) exit;

/**
 * Example cron schedules — daily full import, 30-min availability
 */

// Add 30-min schedule
add_filter('cron_schedules', function($s){
    if (!isset($s['every_30min'])) {
        $s['every_30min'] = [
            'interval' => 1800,
            'display'  => __('Every 30 minutes', 'havenconnect'),
        ];
    }
    return $s;
});

// Daily full import
if (!wp_next_scheduled('hcn_cron_full_import')) {
    wp_schedule_event(time() + 120, 'daily', 'hcn_cron_full_import');
}

add_action('hcn_cron_full_import', function () {
    $settings = hcn_importer_get_settings();
    $apiKey    = $settings['apiKey'];
    $agencyUid = $settings['agencyUid'];
    if (empty($apiKey) || empty($agencyUid)) return;

    // Reuse importer the same way as AJAX does: get list, loop properties
    $logger = $GLOBALS['havenconnect']['logger'] ?? null;
    $api    = new HavenConnect_Api_Client($logger);
    $imp    = $GLOBALS['havenconnect']['importer'] ?? null;
    if (!$imp) return;

    $list = $api->get_featured_list($apiKey, $agencyUid);
    if (!is_array($list) || empty($list)) return;

    foreach ($list as $p) {
        try { $imp->import_property_from_featured($apiKey, $p); } catch (\Throwable $e) {}
    }
});

// Availability every 30 minutes
if (!wp_next_scheduled('hcn_cron_availability')) {
    wp_schedule_event(time() + 300, 'every_30min', 'hcn_cron_availability');
}

add_action('hcn_cron_availability', function () {
    // If you later add availability->sync_all_properties(), call it here.
    // Right now we sync per property as part of import_property_from_featured().
});