<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) { exit; }

class HavenConnect_Search_Shortcode {

  /** @var wpdb */
  private $db;

  public function __construct() {
    global $wpdb;
    $this->db = $wpdb;

    add_shortcode('hcn_property_search', [$this, 'render']);

    add_action('wp_ajax_hcn_property_search',        [$this, 'ajax_search']);
    add_action('wp_ajax_nopriv_hcn_property_search', [$this, 'ajax_search']);
  }

  public function ajax_search() {
    check_ajax_referer('hcn_search_nonce', 'nonce');

    $checkin   = sanitize_text_field($_POST['checkin']   ?? '');
    $checkout  = sanitize_text_field($_POST['checkout']  ?? '');
    $guests    = max(0, (int)   ($_POST['guests']    ?? 0));
    $bedrooms  = max(0, (int)   ($_POST['bedrooms']  ?? 0));
    $bathrooms = max(0, (float) ($_POST['bathrooms'] ?? 0));
    $per_page  = max(1, (int)   ($_POST['per_page']  ?? 12));

    $html = $this->build_results_html($checkin, $checkout, $guests, $bedrooms, $bathrooms, $per_page);

    wp_send_json_success([
      'html' => $html,
    ]);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      'per_page' => 12,
    ], $atts);

    // Enqueue assets
    $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(__DIR__);
    wp_enqueue_script('hcn-search', $base . 'assets/hcn-search.js', [], '1.2.0', true);
    wp_enqueue_style('hcn-tiles',   $base . 'assets/hcn-tiles.css', [], '1.2.0');

    $nonce   = wp_create_nonce('hcn_search_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');

    // Prefill from URL (single source of truth)
    $checkin   = sanitize_text_field($_GET['checkin']   ?? '');
    $checkout  = sanitize_text_field($_GET['checkout']  ?? '');
    $guests    = max(0, (int)   ($_GET['guests']    ?? 0));
    $bedrooms  = max(0, (int)   ($_GET['bedrooms']  ?? 0));
    $bathrooms = max(0, (float) ($_GET['bathrooms'] ?? 0));

    ob_start();
    ?>
    <div class="hcn-search-results" data-hcn-results-root>
      <!-- Hidden carrier form (NO UI) -->
      <form class="hcn-search-form hcn-search-form--hidden"
            aria-hidden="true"
            data-ajax="<?php echo esc_attr($ajaxUrl); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>"
            data-per-page="<?php echo esc_attr((int)$atts['per_page']); ?>">
        <input type="hidden" name="checkin"   value="<?php echo esc_attr($checkin); ?>">
        <input type="hidden" name="checkout"  value="<?php echo esc_attr($checkout); ?>">
        <input type="hidden" name="guests"    value="<?php echo esc_attr($guests); ?>">
        <input type="hidden" name="bedrooms"  value="<?php echo esc_attr($bedrooms); ?>">
        <input type="hidden" name="bathrooms" value="<?php echo esc_attr($bathrooms); ?>">
      </form>

      <div class="hcn-search-status" aria-live="polite"></div>

      <div class="hcn-results-wrap">
        <?php
          // Initial SSR so page isn’t empty if JS is delayed.
          echo $this->build_results_html($checkin, $checkout, $guests, $bedrooms, $bathrooms, (int)$atts['per_page']);
        ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private function build_results_html(string $checkin, string $checkout, int $guests, int $bedrooms, float $bathrooms, int $per_page): string {
    $args = [
      'post_type'      => 'hcn_property',
      'post_status'    => 'publish',
      'posts_per_page' => $per_page,
    ];

    // Strict availability filter (rows-per-night, unavailable=0)
    if ($checkin && $checkout) {
      $ids = $this->get_available_property_ids($checkin, $checkout);
      if (!$ids) {
        return '<div class="hcn-empty">No properties available for those dates.</div>';
      }
      $args['post__in'] = $ids;
      $args['orderby'] = 'post__in';
    }

    $meta_query = [];
    if ($guests > 0) {
      $meta_query[] = ['key' => 'sleeps', 'value' => $guests, 'type' => 'NUMERIC', 'compare' => '>='];
    }
    if ($bedrooms > 0) {
      $meta_query[] = ['key' => 'bedrooms', 'value' => $bedrooms, 'type' => 'NUMERIC', 'compare' => '>='];
    }
    if ($bathrooms > 0) {
      $meta_query[] = ['key' => 'bathrooms', 'value' => $bathrooms, 'type' => 'NUMERIC', 'compare' => '>='];
    }
    if ($meta_query) {
      $args['meta_query'] = $meta_query;
    }

    $q = new WP_Query($args);
    if (!$q->have_posts()) {
      return '<div class="hcn-empty">No properties found.</div>';
    }

    ob_start();
    echo '<div class="hcn-results--grid">';

    while ($q->have_posts()) {
      $q->the_post();
      $pid = (int) get_the_ID();

      $title = get_the_title($pid);
      $url   = get_permalink($pid);

      $bd = (int) get_post_meta($pid, 'bedrooms', true);
      $ba = (float) get_post_meta($pid, 'bathrooms', true);
      $sl = (int) get_post_meta($pid, 'sleeps', true);

      $city    = (string) get_post_meta($pid, 'city', true);
      $state   = (string) get_post_meta($pid, 'state', true);
      $country = (string) get_post_meta($pid, 'country', true);
      $sub     = trim(implode(', ', array_filter([$city, $state, $country])));

      $img = $this->get_card_image_url($pid);

      $min_price = $this->get_min_price($pid, $checkin, $checkout);

      ?>
		<div class="hcn-tile">
		  <a class="hcn-tile__media" href="<?php echo esc_url($url); ?>">
			<?php if ($img): ?>
			  <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
			<?php else: ?>
			  <div class="hcn-tile__ph"></div>
			<?php endif; ?>
		  </a>

		  <div class="hcn-tile__body">
			<div class="hcn-tile__title-row">
			  <h3 class="hcn-tile__title">
				<a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a>
			  </h3>
			</div>

			<?php if ($sub): ?>
			  <div class="hcn-tile__sub"><?php echo esc_html($sub); ?></div>
			<?php endif; ?>

			<div class="hcn-tile__icons">
			  <span class="hcn-i"><span class="hcn-i__ic">🛏</span><?php echo esc_html($bd); ?></span>
			  <span class="hcn-i"><span class="hcn-i__ic">🛁</span><?php echo esc_html($ba); ?></span>
			  <span class="hcn-i"><span class="hcn-i__ic">👤</span><?php echo esc_html($sl); ?></span>
			</div>

			<?php if ($min_price !== null && $min_price > 0): ?>
			  <div class="hcn-tile__price">From <strong>£<?php echo esc_html(number_format($min_price, 0)); ?></strong> per night</div>
			<?php endif; ?>
		  </div>
		</div>
      <?php
    }

    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
  }

  private function get_card_image_url(int $post_id): string {
    // Prefer cached thumb/featured meta if you have it, fallback to WP image.
    $thumb = (string) get_post_meta($post_id, '_hcn_featured_thumb_url', true);
    if ($thumb) return $thumb;

    $featured = (string) get_post_meta($post_id, '_hcn_featured_image_url', true);
    if ($featured) return $featured;

    $thumb_id = get_post_thumbnail_id($post_id);
    if ($thumb_id) {
      return (string) (wp_get_attachment_image_url($thumb_id, 'medium') ?: '');
    }

    return '';
  }

  private function get_min_price(int $post_id, string $checkin = '', string $checkout = ''): ?float {
    $table  = $this->db->prefix . 'hcn_availability';
    $exists = $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return null;

    // With dates: lowest nightly price in selected window
    if ($checkin && $checkout) {
      $in  = strtotime($checkin);
      $out = strtotime($checkout);
      if ($in && $out && $out > $in) {
        $sql = $this->db->prepare(
          "SELECT MIN(price) FROM {$table}
           WHERE post_id = %d AND unavailable = 0 AND price IS NOT NULL
             AND for_date >= %s AND for_date < %s",
          $post_id,
          gmdate('Y-m-d', $in),
          gmdate('Y-m-d', $out)
        );
        $min = $this->db->get_var($sql);
        return $min !== null ? (float) $min : null;
      }
    }

    // No dates: lowest known nightly price
    $sql = $this->db->prepare(
      "SELECT MIN(price) FROM {$table}
       WHERE post_id = %d AND unavailable = 0 AND price IS NOT NULL",
      $post_id
    );
    $min = $this->db->get_var($sql);
    return $min !== null ? (float) $min : null;
  }

  private function get_available_property_ids(string $checkin, string $checkout): array {
    $table  = $this->db->prefix . 'hcn_availability';
    $exists = $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return [];

    $in     = strtotime($checkin);
    $out    = strtotime($checkout);
    $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;
    if (!$in || !$out || $nights <= 0) return [];

    $sql = $this->db->prepare(
      "SELECT post_id
       FROM {$table}
       WHERE for_date >= %s AND for_date < %s AND unavailable = 0
       GROUP BY post_id
       HAVING COUNT(*) = %d
       LIMIT 2000",
      gmdate('Y-m-d', $in),
      gmdate('Y-m-d', $out),
      $nights
    );

    $ids = $this->db->get_col($sql);
    return array_map('intval', $ids ?: []);
  }
}