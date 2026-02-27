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
define('HCN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HCN_AVAIL_DB_VERSION', 1);

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
hcn_require('register-post-types.php');
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
hcn_require('class-availability-cleanup-hooks.php');
hcn_require('class-search-shortcode.php');
hcn_require('class-search-bar-shortcode.php');
hcn_require('class-map-shortcode.php');
hcn_require('class-loggia-availability-importer.php');
// hcn_require('class-availability-cron.php'); // optional — cron to clear stale availability
// hcn_require('class-import-cron.php');        // optional — cron imports


// Availability table install/upgrade on activation
register_activation_hook(HCN_FILE, function () {
  require_once HCN_DIR . 'includes/class-availability-table.php';
  HavenConnect_Availability_Table::install_or_upgrade();
  update_option('hcn_avail_db_version', HCN_AVAIL_DB_VERSION);
});

/**
 * Safety fallback: create/repair table only when needed (admins only)
 * - runs in wp-admin only
 * - runs only when schema version is behind
 */
add_action('admin_init', function () {
  if (!current_user_can('manage_options')) return;

  $v = (int) get_option('hcn_avail_db_version', 0);
  if ($v >= HCN_AVAIL_DB_VERSION) return;

  if (class_exists('HavenConnect_Availability_Table')) {
    HavenConnect_Availability_Table::install_or_upgrade();
    update_option('hcn_avail_db_version', HCN_AVAIL_DB_VERSION);
  }
});

/**
 * BOOTSTRAP
 *
 * IMPORTANT:
 *  - Build ALL singletons (logger, taxonomy handler, api, photos, importer, admin) inside `init`
 *    so the logger session used by taxonomy registration and the importer/AJAX is the SAME one.
 *  - Use priority 5 (earlier than default 10) so these singletons exist before the AJAX
 *    file registers its handlers on `init` (default priority).
 */
add_action('init', function () {

    // --- Core singletons (build once, shared everywhere)
    $logger = new HavenConnect_Logger('hcn_log');
    $tax = new HavenConnect_Taxonomy_Handler('hcn_property', $logger);

    $api    = new HavenConnect_Api_Client($logger);
    $photos = new HavenConnect_Photo_Sync($logger);
    // --- Loggia provider singletons
    hcn_require('providers/loggia/class-loggia-client.php');
    hcn_require('providers/loggia/class-loggia-importer.php');

    $loggia_importer = new HavenConnect_Loggia_Importer($logger, $photos);

    // Availability importer (OAuth pending)
    $availability = new HavenConnect_Availability_Importer($api, $logger);

    // Property importer & Admin
    $importer = new HavenConnect_Property_Importer($api, $tax, $photos, $logger);
	
	// --- Providers (Option B architecture)
	hcn_require('providers/hostfully/class-hostfully-mapper.php');
	hcn_require('providers/hostfully/class-hostfully-provider.php');

	hcn_require('providers/loggia/class-loggia-mapper.php');
	hcn_require('providers/loggia/class-loggia-provider.php');

	$hostfully_provider = new HavenConnect_Hostfully_Provider($api, $importer, $logger);
	$loggia_provider    = new HavenConnect_Loggia_Provider($logger, $photos, $loggia_importer);
    $admin    = new HavenConnect_Admin($importer, $logger);

    $GLOBALS['havenconnect'] = [
      'logger'       => $logger,
      'api'          => $api,
      'tax'          => $tax,
      'photos'       => $photos,
      'availability' => $availability,
      'importer'     => $importer,
      'admin'        => $admin,
      'loggia_importer' => $loggia_importer,
	  'providers' => [
		  'hostfully' => $hostfully_provider,
		  'loggia'    => $loggia_provider,
		],
    ];

    // Ensure the CPT supports classic custom fields
    $cpt = 'hcn_property';
    if (post_type_exists($cpt)) {
        add_post_type_support($cpt, 'custom-fields');
    }
	
	// --- Shortcodes (instantiated here so they share the same init pass)
    $GLOBALS['havenconnect']['map']               = new HavenConnect_Map_Shortcode();
    $GLOBALS['havenconnect']['search_bar']        = new HavenConnect_Search_Bar_Shortcode();
    $GLOBALS['havenconnect']['search_shortcode']  = new HavenConnect_Search_Shortcode();

}, 5);

/**
 * Load AJAX importer AFTER bootstrap so handlers see the real singletons.
 * The AJAX file itself registers handlers on init (default 10), which now occurs
 * after the above bootstrap (priority 5).
 */
hcn_require('class-import-ajax.php');

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