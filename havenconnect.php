<?php
/**
 * Plugin Name: HavenConnect
 * Description: Sync Featured properties and availability from Hostfully PMS.
 * Version: 0.2.4
 * Author: Tommy Leyland + M365 Copilot
 */

if (!defined('ABSPATH')) exit;

define('HCN_VER',  '0.2.4');
define('HCN_FILE', __FILE__);
define('HCN_DIR',  plugin_dir_path(__FILE__));
define('HCN_URL',  plugin_dir_url(__FILE__));

/**
 * Loader helper
 */
function hcn_require($rel) {
    $path = HCN_DIR . 'includes/' . ltrim($rel, '/');
    if (file_exists($path)) require_once $path;
}

/**
 * Includes (order matters: core, CPT, utilities, importers, admin)
 */
hcn_require('helpers.php');
hcn_require('register-post-types.php');          // <-- must exist and register CPT 'hcn_property'
hcn_require('class-logger.php');
hcn_require('class-api-client.php');
hcn_require('class-taxonomy-handler.php');
hcn_require('class-photo-sync.php');
hcn_require('class-availability-table.php');
hcn_require('class-availability-importer.php');
hcn_require('class-property-importer.php');
hcn_require('class-admin-metabox.php');
hcn_require('class-admin.php');
hcn_require('admin-save-hooks.php');

/**
 * Create availability table on activation
 */
register_activation_hook(HCN_FILE, ['HavenConnect_Availability_Table', 'install_table']);

/**
 * Safety fallback: create/repair table if missing (admins only)
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'hcn_availability';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
        if (class_exists('HavenConnect_Availability_Table')) {
            error_log('[HavenConnect] availability table missing; installing now.');
            HavenConnect_Availability_Table::install_table();
        }
    }
});

/**
 * BOOTSTRAP: run on core 'init' (NOT 'acf/init')
 * This ensures the plugin works regardless of whether ACF is active.
 */
add_action('init', function () {

    // --- Core singletons
    $logger = new HavenConnect_Logger('hcn_log');
    $api    = new HavenConnect_Api_Client($logger);
    $tax    = new HavenConnect_Taxonomy_Handler($logger);
    $photos = new HavenConnect_Photo_Sync($logger);

    // Availability importer (won't work with API key for calendars until OAuth is provided; safe to construct)
    $availability = new HavenConnect_Availability_Importer($api, $logger);

    // Property importer & Admin
    $importer = new HavenConnect_Property_Importer($api, $tax, $photos, $logger);
    $admin    = new HavenConnect_Admin($importer, $logger);

    $GLOBALS['havenconnect'] = [
        'logger'       => $logger,
        'api'          => $api,
        'tax'          => $tax,
        'photos'       => $photos,
        'availability' => $availability,
        'importer'     => $importer,
        'admin'        => $admin,
    ];

    /**
     * Ensure the CPT supports the classic "Custom Fields" box.
     * If your register-post-types.php already includes 'custom-fields' in 'supports',
     * this is harmless; otherwise it adds it here.
     */
    $cpt = 'hcn_property';
    if (post_type_exists($cpt)) {
        add_post_type_support($cpt, 'custom-fields');
    }
});

/**
 * Always show native WP Custom Fields box if ACF is active (ACF hides it by default).
 * Harmless if ACF is not present—the filter just won't ever fire.
 */
add_filter('acf/settings/remove_wp_meta_box', '__return_false');

/**
 * Register REST-visible meta for CPT 'hcn_property'
 * Gutenberg (and many builders) only surface meta that is registered + show_in_rest=true.
 */
add_action('init', function () {

    $post_type = 'hcn_property';

    // ---- string fields
    $keys_string = [
        'address_line1','address_line2','city','state','postcode',
        'country_code','property_type','listing_type','room_type','area_unit',
        'license_number','license_expiry','_hcn_featured_image_url',
    ];

    foreach ($keys_string as $k) {
        register_post_meta($post_type, $k, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function(){ return current_user_can('edit_posts'); },
        ]);
    }

    // ---- integer fields
    $keys_int = ['bedrooms','beds','sleeps','check_in_from','check_in_to','min_stay','max_stay','floor_count'];
    foreach ($keys_int as $k) {
        register_post_meta($post_type, $k, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'integer',
            'auth_callback' => function(){ return current_user_can('edit_posts'); },
        ]);
    }

    // ---- numeric/float fields
    $keys_num = ['bathrooms','latitude','longitude','area_size'];
    foreach ($keys_num as $k) {
        register_post_meta($post_type, $k, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'number',
            'auth_callback' => function(){ return current_user_can('edit_posts'); },
        ]);
    }

    // ---- array field for gallery URLs
    register_post_meta($post_type, '_hcn_gallery_urls', [
        'show_in_rest' => [
            'schema' => [
                'type'  => 'array',
                'items' => ['type' => 'string']
            ],
        ],
        'single'        => true,
        'type'          => 'array',
        'auth_callback' => function(){ return current_user_can('edit_posts'); },
    ]);

    /**
     * Optional: if you want discrete gallery URL keys to appear in REST as well:
     * (uncomment and adjust max if you need them)
     */
    /*
    for ($i=1; $i<=60; $i++) {
        $k = 'hcn_gallery_url_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        register_post_meta($post_type, $k, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function(){ return current_user_can('edit_posts'); },
        ]);
    }
    */
});