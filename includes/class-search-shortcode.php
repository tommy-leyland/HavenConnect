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
    $per_page  = max(1, (int)   ($_POST['per_page']  ?? 100));

    $html = $this->build_results_html($checkin, $checkout, $guests, $bedrooms, $bathrooms, $per_page);

    wp_send_json_success([
      'html' => $html,
    ]);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      'per_page' => 100,
    ], $atts);

    // Enqueue assets
    if (defined('HCN_PLUGIN_URL')) {
      wp_enqueue_script('hcn-search', HCN_PLUGIN_URL . 'assets/hcn-search.js', [], '1.2.0', true);
      wp_enqueue_style('hcn-tiles',   HCN_PLUGIN_URL . 'assets/hcn-tiles.css', [], '1.2.0');
    } else {
      // Fallback if constant not defined
      $base = plugin_dir_url(__DIR__);
      wp_enqueue_script('hcn-search', $base . 'assets/hcn-search.js', [], '1.2.0', true);
      wp_enqueue_style('hcn-tiles',   $base . 'assets/hcn-tiles.css', [], '1.2.0');
    }

    $nonce   = wp_create_nonce('hcn_search_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');

    // Prefill from URL (source of truth)
    $checkin   = sanitize_text_field($_GET['checkin']   ?? '');
    $checkout  = sanitize_text_field($_GET['checkout']  ?? '');
    $guests    = max(0, (int)   ($_GET['guests']    ?? 0));
    $bedrooms  = max(0, (int)   ($_GET['bedrooms']  ?? 0));
    $bathrooms = max(0, (float) ($_GET['bathrooms'] ?? 0));

    ob_start();
    ?>
    <div class="hcn-search-results" data-hcn-results-root>
      <!-- Hidden carrier form (NO visible UI in results shortcode) -->
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
          // SSR initial render
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

    // Availability filter
    if ($checkin && $checkout) {
      $ids = $this->get_available_property_ids($checkin, $checkout);
      if (empty($ids)) {
        return '<div class="hcn-empty">No properties available for those dates.</div>';
      }
      $args['post__in'] = $ids;
      $args['orderby'] = 'post__in';
    }

    // Meta filters
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
    if (!empty($meta_query)) {
      $args['meta_query'] = $meta_query;
    }

    $q = new WP_Query($args);
    if (!$q->have_posts()) {
      return '<div class="hcn-empty">No properties found.</div>';
    }

    // Icon assets (image icons like your original implementation)
    $icon_user  = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL . 'assets/img/user2.jpg'  : plugin_dir_url(__DIR__) . 'assets/img/user2.jpg';
    $icon_beds  = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL . 'assets/img/beds.jpg'   : plugin_dir_url(__DIR__) . 'assets/img/beds.jpg';
    $icon_baths = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL . 'assets/img/baths.jpg'  : plugin_dir_url(__DIR__) . 'assets/img/baths.jpg';

    ob_start();

    echo '<div class="hcn-results--grid">';

    while ($q->have_posts()) {
      $q->the_post();

      $pid = (int) get_the_ID();

      $bd = (int) get_post_meta($pid, 'bedrooms', true);
      $ba = (float) get_post_meta($pid, 'bathrooms', true);
      $sl = (int) get_post_meta($pid, 'sleeps', true);

      $featured = $this->get_card_image_url($pid);

      $gallery = get_post_meta($pid, '_hcn_gallery_urls', true);
      if (!is_array($gallery)) { $gallery = []; }
      $gallery_count = count($gallery);

      $city    = (string) get_post_meta($pid, 'city', true);
      $state   = (string) get_post_meta($pid, 'state', true);
      $country = (string) get_post_meta($pid, 'country', true);
      $sub     = trim(implode(', ', array_filter([$city, $state, $country])));

      // two “feature pills” from hcn_feature taxonomy
      $pills = [];
      $terms = get_the_terms($pid, 'hcn_feature');
      if (is_array($terms)) {
        $terms = array_values($terms);
        for ($i = 0; $i < min(2, count($terms)); $i++) {
          $pills[] = $terms[$i]->name;
        }
      }

      // optional metas
      $badge = (string) get_post_meta($pid, 'promo_badge', true);
      $fav   = (string) get_post_meta($pid, 'guest_favourite', true); // "1" to show

      $min_price = $this->get_from_price($pid, $checkin, $checkout);

      $title = get_the_title($pid);
      $url   = get_permalink($pid);
      ?>
      <div class="hcn-tile">
        <a class="hcn-tile__media" href="<?php echo esc_url($url); ?>">
          <?php if ($featured): ?>
            <img src="<?php echo esc_url($featured); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
          <?php else: ?>
            <div class="hcn-tile__ph"></div>
          <?php endif; ?>

          <?php if (!empty($badge)): ?>
            <div class="hcn-tile__badge"><?php echo esc_html($badge); ?></div>
          <?php endif; ?>

          <?php if ($gallery_count > 1): ?>
            <div class="hcn-tile__dots" aria-hidden="true">
              <?php
                $dots = min(5, $gallery_count);
                for ($d = 0; $d < $dots; $d++) {
                  $cls = ($d === 0) ? 'hcn-tile__dot is-active' : 'hcn-tile__dot';
                  echo '<span class="' . esc_attr($cls) . '"></span>';
                }
              ?>
            </div>
          <?php endif; ?>
        </a>

        <div class="hcn-tile__body">
          <div class="hcn-tile__title-row">
            <h3 class="hcn-tile__title">
              <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a>
            </h3>
            <?php if (!empty($fav)): ?>
              <span class="hcn-tile__cta">Guest favourite</span>
            <?php endif; ?>
          </div>

          <?php if (!empty($sub)): ?>
            <div class="hcn-tile__sub"><?php echo esc_html($sub); ?></div>
          <?php endif; ?>

          <!-- ICONS: restored using your image assets + CSS-expected structure -->
          <div class="hcn-tile__icons">
            <span class="hcn-i">
              <span class="hcn-i__ic"><img src="<?php echo esc_url($icon_user); ?>" alt=""></span>
              <?php echo esc_html($sl); ?>
            </span>

            <span class="hcn-i">
              <span class="hcn-i__ic"><img src="<?php echo esc_url($icon_beds); ?>" alt=""></span>
              <?php echo esc_html($bd); ?>
            </span>

            <span class="hcn-i">
              <span class="hcn-i__ic"><img src="<?php echo esc_url($icon_baths); ?>" alt=""></span>
              <?php echo esc_html($ba); ?>
            </span>
          </div>

          <?php if (!empty($pills)): ?>
            <div class="hcn-tile__pills">
              <?php foreach ($pills as $pill): ?>
                <span class="hcn-tile__pill"><?php echo esc_html($pill); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

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

  private function get_from_price(int $post_id, string $checkin = '', string $checkout = ''): ?float {
    $table  = $this->db->prefix . 'hcn_availability';
    $exists = $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return null;

    // Date window: nights are >= checkin and < checkout
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

    $in  = strtotime($checkin);
    $out = strtotime($checkout);
    $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;
    if (!$in || !$out || $nights <= 0) return [];

    // Strict match: row per night with unavailable=0
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