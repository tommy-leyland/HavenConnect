<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Search_Shortcode {

  /** @var wpdb */
  private $db;

  public function __construct($wpdb) {
    $this->db = $wpdb;
    add_shortcode('hcn_property_search', [$this, 'render']);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      'per_page' => 12,
    ], $atts);

    $checkin  = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : '';
    $checkout = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : '';

    // Simple front-end form
    ob_start(); ?>
      <form method="get" class="hcn-search-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <div>
          <label>Check in</label><br>
          <input type="date" name="checkin" value="<?php echo esc_attr($checkin); ?>">
        </div>
        <div>
          <label>Check out</label><br>
          <input type="date" name="checkout" value="<?php echo esc_attr($checkout); ?>">
        </div>
        <button type="submit">Search</button>
      </form>
    <?php

    // If no dates yet, just show latest properties
    $args = [
      'post_type'      => 'hcn_property',
      'post_status'    => 'publish',
      'posts_per_page' => (int)$atts['per_page'],
    ];

    if ($checkin && $checkout) {
      $ids = $this->get_available_property_ids($checkin, $checkout);

      // No matches
      if (empty($ids)) {
        echo '<p>No properties available for those dates.</p>';
        return ob_get_clean();
      }

      // Restrict query to matching IDs
      $args['post__in'] = $ids;
      $args['orderby']  = 'post__in';
    }

    $q = new WP_Query($args);

    echo '<div class="hcn-results" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px;">';
    while ($q->have_posts()) {
      $q->the_post();
      $pid = get_the_ID();

      // Show a couple fields you just imported:
      $space = get_post_meta($pid, 'property_space', true);
      $neigh = get_post_meta($pid, 'property_neighbourhood', true);

      echo '<article style="border:1px solid #ddd;padding:12px;border-radius:10px;">';
      echo '<h3 style="margin:0 0 8px 0;">' . esc_html(get_the_title()) . '</h3>';
      echo '<div style="color:#666;font-size:13px;margin-bottom:8px;">' . esc_html(wp_trim_words(get_the_excerpt(), 20)) . '</div>';
      if ($space) echo '<div><strong>The space:</strong> ' . esc_html(wp_trim_words($space, 18)) . '</div>';
      if ($neigh) echo '<div style="margin-top:6px;"><strong>Neighbourhood:</strong> ' . esc_html(wp_trim_words($neigh, 18)) . '</div>';
      echo '</article>';
    }
    echo '</div>';

    wp_reset_postdata();

    return ob_get_clean();
  }

  /**
   * IMPORTANT:
   * You must align this to whatever your availability table actually is.
   *
   * Concept:
   * - Return property IDs where ALL nights between checkin (inclusive) and checkout (exclusive) are available.
   */
  private function get_available_property_ids(string $checkin, string $checkout): array {
    // Validate dates
    $in  = strtotime($checkin);
    $out = strtotime($checkout);
    if (!$in || !$out || $out <= $in) return [];

    // === Adjust THESE to match your table schema ===
    $table = $this->db->prefix . 'hcn_availability'; // <-- if your table name differs, change this
    $col_post = 'post_id';
    $col_date = 'date';
    $col_avail = 'is_available'; // or 'available' or similar
    // =============================================

    // Number of nights
    $nights = (int)(($out - $in) / DAY_IN_SECONDS);

    // We need properties that have "available" rows for EVERY night in range.
    // This pattern works if table stores one row per property per date.
    $sql = $this->db->prepare("
      SELECT {$col_post} AS pid
      FROM {$table}
      WHERE {$col_date} >= %s
        AND {$col_date} < %s
        AND {$col_avail} = 1
      GROUP BY {$col_post}
      HAVING COUNT(*) = %d
      LIMIT 500
    ", gmdate('Y-m-d', $in), gmdate('Y-m-d', $out), $nights);

    $rows = $this->db->get_col($sql);
    return array_map('intval', $rows ?: []);
  }
}