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

      if (current_user_can('manage_options') && isset($_GET['hcn_debug']) && $_GET['hcn_debug'] == '1') {
      echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccc;padding:10px;margin:10px 0;">';
      echo "Matched IDs (first 20): " . implode(',', array_slice($ids, 0, 20)) . "\n";

      foreach (array_slice($ids, 0, 10) as $pid) {
        $p = get_post($pid);
        if (!$p) {
          echo "ID {$pid}: get_post() = NULL\n";
          continue;
        }
        echo "ID {$pid}: type={$p->post_type} status={$p->post_status} title=" . $p->post_title . "\n";
      }
      echo '</pre>';
    }

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
    global $wpdb;

    $debug = current_user_can('manage_options') && isset($_GET['hcn_debug']) && $_GET['hcn_debug'] == '1';

    $table = $wpdb->prefix . 'hcn_availability';

    // Basic sanity: do we have properties at all?
    $published = (int) $wpdb->get_var("
      SELECT COUNT(*) FROM {$wpdb->posts}
      WHERE post_type = 'hcn_property' AND post_status = 'publish'
    ");

    // Table exists?
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    $row_count = 0;
    $minmax = null;

    if ($exists === $table) {
      $row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
      $minmax = $wpdb->get_row("SELECT MIN(for_date) AS min_date, MAX(for_date) AS max_date FROM {$table}", ARRAY_A);
    }

    $in  = strtotime($checkin);
    $out = strtotime($checkout);
    $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;

    if ($debug) {
      echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccc;padding:10px;margin:10px 0;">';
      echo "HCN Debug\n";
      echo "Published properties: {$published}\n";
      echo "Availability table: {$table}\n";
      echo "Table exists: " . ($exists === $table ? 'YES' : 'NO') . "\n";
      echo "Availability rows: {$row_count}\n";
      if ($minmax) {
        echo "Min date: {$minmax['min_date']} | Max date: {$minmax['max_date']}\n";
      }
      echo "Requested: {$checkin} -> {$checkout} ({$nights} nights)\n";
      echo '</pre>';
    }

    // If table missing or empty, nothing can match
    if ($exists !== $table || $row_count === 0) return [];

    // If bad date range, nothing to do
    if (!$in || !$out || $out <= $in || $nights <= 0) return [];

    // IMPORTANT: strict match requires a row per night with unavailable=0
    $sql = $wpdb->prepare("
      SELECT post_id
      FROM {$table}
      WHERE for_date >= %s
        AND for_date < %s
        AND unavailable = 0
      GROUP BY post_id
      HAVING COUNT(*) = %d
      LIMIT 1000
    ", gmdate('Y-m-d', $in), gmdate('Y-m-d', $out), $nights);

    $ids = $wpdb->get_col($sql);

    if ($debug) {
      echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccc;padding:10px;margin:10px 0;">';
      echo "SQL:\n{$sql}\n\n";
      echo "Last DB error:\n" . ($wpdb->last_error ? $wpdb->last_error : '(none)') . "\n";
      echo "Matches: " . (is_array($ids) ? count($ids) : 0) . "\n";
      echo '</pre>';
    }

    return array_map('intval', $ids ?: []);
  }
}