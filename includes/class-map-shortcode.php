<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Map_Shortcode {

  public function __construct() {
    add_shortcode('hcn_property_map', [$this, 'render']);

    add_action('wp_ajax_hcn_map_properties', [$this, 'ajax_properties']);
    add_action('wp_ajax_nopriv_hcn_map_properties', [$this, 'ajax_properties']);

    add_action('wp_ajax_hcn_quote_nightly', [$this, 'ajax_quote_nightly']);
    add_action('wp_ajax_nopriv_hcn_quote_nightly', [$this, 'ajax_quote_nightly']);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      'per_page' => 250,
      'height'   => '560px',
    ], $atts);

    $opts = get_option('havenconnect_settings', []);
    $google_key = trim($opts['google_maps_api_key'] ?? '');
    if (!$google_key) {
      return '<p><strong>Map unavailable:</strong> missing Google Maps API key in HavenConnect settings.</p>';
    }

    // Google Maps + map JS
    wp_enqueue_script(
      'google-maps',
      'https://maps.googleapis.com/maps/api/js?key=' . urlencode($google_key),
      [],
      null,
      true
    );

    $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(__DIR__) . '/';
    wp_enqueue_script('hcn-map', $base . 'assets/hcn-map.js', ['google-maps'], '1.1.3', true);
    wp_enqueue_style('hcn-map', $base . 'assets/hcn-map.css', [], '1.1.3');

    $nonce = wp_create_nonce('hcn_map_nonce');

    ob_start(); ?>
<div class="hcn-map-wrap"
     data-ajax="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-per-page="<?php echo esc_attr((int)$atts['per_page']); ?>">
  <div id="hcn-map" style="width:100%;height:100vh; ?>;"></div>
  <div class="hcn-map-popover"></div>
</div>
    <?php
    return ob_get_clean();
  }

  /** Marker list endpoint */
  public function ajax_properties() {
    check_ajax_referer('hcn_map_nonce', 'nonce');

    $checkin   = isset($_POST['checkin']) ? sanitize_text_field($_POST['checkin']) : '';
    $checkout  = isset($_POST['checkout']) ? sanitize_text_field($_POST['checkout']) : '';
    $guests    = isset($_POST['guests']) ? max(0, (int)$_POST['guests']) : 0;
    $bedrooms  = isset($_POST['bedrooms']) ? max(0, (int)$_POST['bedrooms']) : 0;
    $bathrooms = isset($_POST['bathrooms']) ? max(0, (float)$_POST['bathrooms']) : 0;
    $limit     = isset($_POST['per_page']) ? max(1, (int)$_POST['per_page']) : 250;

    $args = [
      'post_type'      => 'hcn_property',
      'post_status'    => 'publish',
      'posts_per_page' => $limit,
      'fields'         => 'ids',
      'no_found_rows'  => true,
    ];

    // Meta filters
    $meta_query = [];
    if ($guests > 0)    $meta_query[] = ['key'=>'sleeps','value'=>$guests,'type'=>'NUMERIC','compare'=>'>='];
    if ($bedrooms > 0)  $meta_query[] = ['key'=>'bedrooms','value'=>$bedrooms,'type'=>'NUMERIC','compare'=>'>='];
    if ($bathrooms > 0) $meta_query[] = ['key'=>'bathrooms','value'=>$bathrooms,'type'=>'NUMERIC','compare'=>'>='];
    if ($meta_query) $args['meta_query'] = $meta_query;
    

    // Availability IDs (same logic as your search shortcode: strict rows per night unavailable=0)
    if ($checkin && $checkout) {
      global $wpdb;
      $table = $wpdb->prefix . 'hcn_availability';

      $in  = strtotime($checkin);
      $out = strtotime($checkout);
      $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;

      if ($nights > 0) {
        $sql = $wpdb->prepare("
          SELECT post_id
          FROM {$table}
          WHERE for_date >= %s
            AND for_date < %s
            AND unavailable = 0
          GROUP BY post_id
          HAVING COUNT(*) = %d
          LIMIT 2000
        ", gmdate('Y-m-d', $in), gmdate('Y-m-d', $out), $nights);

        $ids = $wpdb->get_col($sql);
        $ids = array_map('intval', $ids ?: []);

        if (empty($ids)) {
          wp_send_json_success(['items' => []]);
        }

        $args['post__in'] = $ids;
        $args['orderby']  = 'post__in';
      }
    }

    $q = new WP_Query($args);
    $post_ids = $q->posts ?: [];

    // Build a from-price map for returned posts using the same availability table
    $price_map = [];
    if (!empty($post_ids)) {
      global $wpdb;
      $table = $wpdb->prefix . 'hcn_availability';

      $in  = $checkin ? strtotime($checkin) : 0;
      $out = $checkout ? strtotime($checkout) : 0;

      // IN (%d,%d,...) for post ids
      $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
      $args = array_map('intval', $post_ids);

      if ($in && $out && $out > $in) {
        $args[] = gmdate('Y-m-d', $in);
        $args[] = gmdate('Y-m-d', $out);

        $sql = $wpdb->prepare(
          "SELECT post_id, MIN(price) AS min_price
          FROM {$table}
          WHERE post_id IN ($placeholders)
            AND unavailable = 0
            AND price IS NOT NULL
            AND for_date >= %s
            AND for_date < %s
          GROUP BY post_id",
          $args
        );
      } else {
        $sql = $wpdb->prepare(
          "SELECT post_id, MIN(price) AS min_price
          FROM {$table}
          WHERE post_id IN ($placeholders)
            AND unavailable = 0
            AND price IS NOT NULL
          GROUP BY post_id",
          $args
        );
      }

      $rows = $wpdb->get_results($sql, ARRAY_A);
      if (is_array($rows)) {
        foreach ($rows as $r) {
          $pid = (int)($r['post_id'] ?? 0);
          $mp  = $r['min_price'] ?? null;
          if ($pid && $mp !== null) $price_map[$pid] = (float)$mp;
        }
      }
    }

    $items = [];

    $asset_base = defined('HCN_PLUGIN_URL')
      ? HCN_PLUGIN_URL
      : trailingslashit(plugin_dir_url(dirname(__FILE__))) . '../';

    $icons = [
      'user' => $asset_base . 'assets/img/user2.jpg',
      'bed'  => $asset_base . 'assets/img/beds.jpg',
      'bath' => $asset_base . 'assets/img/baths.jpg',
    ];

    foreach ($post_ids as $pid) {
      $lat = get_post_meta($pid, 'latitude', true);
      $lng = get_post_meta($pid, 'longitude', true);
      if ($lat === '' || $lng === '') continue;

      $uid = (string)get_post_meta($pid, '_havenconnect_uid', true);

      // 1) explicit thumb meta (fast)
      $thumb = (string) get_post_meta($pid, '_hcn_featured_thumb_url', true);

      // 2) fallback to featured image url (might be large)
      if (!$thumb) $thumb = (string) get_post_meta($pid, '_hcn_featured_image_url', true);

      // 3) fallback to WP attachment size
      if (!$thumb) {
        $thumb_id = get_post_thumbnail_id($pid);
        if ($thumb_id) {
          $thumb = wp_get_attachment_image_url($thumb_id, 'thumbnail') ?: '';
        }
      }

      $items[] = [
        'post_id'   => (int)$pid,
        'uid'       => $uid,
        'title'     => get_the_title($pid),
        'url'       => get_permalink($pid),
        'lat'       => (float)$lat,
        'lng'       => (float)$lng,
        'sleeps'    => (int)get_post_meta($pid, 'sleeps', true),
        'bedrooms'  => (int)get_post_meta($pid, 'bedrooms', true),
        'bathrooms' => (float)get_post_meta($pid, 'bathrooms', true),
        // Optional cached display (if you later store it):
        'from' => isset($price_map[$pid]) ? round($price_map[$pid], 0) : '',
        'currency'  => (string)(get_post_meta($pid, 'currency', true) ?: 'GBP'),
        'thumb' => $thumb,
        'icons'  => $icons,
        'sub' => (string) get_post_meta($pid, 'city', true),
        'provider' => (string) get_post_meta($pid, 'hcn_source', true) ?: 'hostfully',
        'hostfully_uid' => (string) get_post_meta($pid, 'hostfully_uid', true),
      ];
    }

    wp_send_json_success(['items' => $items]);
  }

  /** Quote endpoint: totalWithTaxes -> per-night */
  public function ajax_quote_nightly() {
    check_ajax_referer('hcn_map_nonce', 'nonce');

    $uid      = sanitize_text_field($_POST['uid'] ?? '');
    $checkin  = sanitize_text_field($_POST['checkin'] ?? '');
    $checkout = sanitize_text_field($_POST['checkout'] ?? '');
    $guests   = max(0, (int)($_POST['guests'] ?? 0));

    if (!$uid || !$checkin || !$checkout) {
      wp_send_json_error(['message' => 'Missing parameters'], 400);
    }

    $in  = strtotime($checkin);
    $out = strtotime($checkout);
    $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;
    if ($nights <= 0) {
      wp_send_json_error(['message' => 'Invalid date range'], 400);
    }

    $cache_key = 'hcn_q_' . md5($uid . '|' . $checkin . '|' . $checkout . '|' . $guests);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      wp_send_json_success($cached);
    }

    $opts  = get_option('havenconnect_settings', []);
    $apiKey = trim($opts['api_key'] ?? '');
    $agencyUid = trim($opts['agency_uid'] ?? '');

    if (!$apiKey) {
      wp_send_json_error(['message' => 'Missing Hostfully API key'], 500);
    }
    if (!$agencyUid) {
      wp_send_json_error(['message' => 'Missing Hostfully agency UID'], 500);
    }

    if (empty($GLOBALS['havenconnect']['api']) || !method_exists($GLOBALS['havenconnect']['api'], 'calculate_quote')) {
      wp_send_json_error(['message' => 'API client missing calculate_quote()'], 500);
    }

    $quote = $GLOBALS['havenconnect']['api']->calculate_quote($apiKey, $agencyUid, $uid, $checkin, $checkout, $guests);

    // Prefer totalWithTaxes (your preference)
    $currency = (string)($quote['currency'] ?? $quote['quote']['currency'] ?? 'GBP');

    $totalWithTaxes = null;

    // Common shapes: top-level or nested
    $candidates = [
      $quote['totalWithTaxes'] ?? null,
      $quote['quote']['totalWithTaxes'] ?? null,
      $quote['totals']['totalWithTaxes'] ?? null,
      $quote['quote']['totals']['totalWithTaxes'] ?? null,
    ];

    // First numeric candidate wins
    foreach ($candidates as $c) {
      if (is_numeric($c)) { $totalWithTaxes = (float)$c; break; }
    }

    // Fallbacks (some accounts return different keys)
    if (!$totalWithTaxes || $totalWithTaxes <= 0) {
      $fallbacks = [
        $quote['totalAmount'] ?? null,
        $quote['amount'] ?? null,
        $quote['quote']['totalAmount'] ?? null,
        $quote['totals']['totalAmount'] ?? null,
      ];
      foreach ($fallbacks as $c) {
        if (is_numeric($c)) { $totalWithTaxes = (float)$c; break; }
      }
    }

    if (!$totalWithTaxes || $totalWithTaxes <= 0) {
      if (!empty($GLOBALS['havenconnect']['logger'])) {
        $GLOBALS['havenconnect']['logger']->log("QUOTE MISSING TOTALS for {$uid}: " . substr(wp_json_encode($quote), 0, 2000));
        $GLOBALS['havenconnect']['logger']->save();
      }
      wp_send_json_error(['message' => 'Quote returned no usable totalWithTaxes'], 422);
    }

    $perNight = $totalWithTaxes / $nights;

    $result = [
      'currency' => $currency,
      'total'    => round($totalWithTaxes, 2),
      'perNight' => round($perNight, 2),
      'nights'   => $nights,
    ];

    set_transient($cache_key, $result, 20 * MINUTE_IN_SECONDS);
    wp_send_json_success($result);
  }
}