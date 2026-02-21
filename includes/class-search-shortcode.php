<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Search_Shortcode {

  /** @var wpdb */
  private $db;

  public function __construct($wpdb) {
    $this->db = $wpdb;
    add_shortcode('hcn_property_search', [$this, 'render']);

    add_action('wp_ajax_hcn_property_search', [$this, 'ajax_search']);
    add_action('wp_ajax_nopriv_hcn_property_search', [$this, 'ajax_search']);

  }

  public function ajax_search() {
    check_ajax_referer('hcn_search_nonce', 'nonce');

    $checkin   = isset($_POST['checkin']) ? sanitize_text_field($_POST['checkin']) : '';
    $checkout  = isset($_POST['checkout']) ? sanitize_text_field($_POST['checkout']) : '';
    $guests    = isset($_POST['guests']) ? max(0, (int) $_POST['guests']) : 0;
    $bedrooms  = isset($_POST['bedrooms']) ? max(0, (int) $_POST['bedrooms']) : 0;
    $bathrooms = isset($_POST['bathrooms']) ? max(0, (float) $_POST['bathrooms']) : 0;

    $per_page  = isset($_POST['per_page']) ? max(1, (int) $_POST['per_page']) : 12;

    $html = $this->build_results_html($checkin, $checkout, $guests, $bedrooms, $bathrooms, $per_page);

    wp_send_json_success([
      'html' => $html,
    ]);
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
        return '<p>No properties available for those dates.</p>';
      }
      $args['post__in'] = $ids;
      $args['orderby']  = 'post__in';
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
      return '<p>No properties found.</p>';
    }

    ob_start();
    echo '<div class="hcn-results" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px;">';

    while ($q->have_posts()) {
      $q->the_post();
      $pid = get_the_ID();

      $space = (string) get_post_meta($pid, 'property_space', true);
      $neigh = (string) get_post_meta($pid, 'property_neighbourhood', true);

      $bd = get_post_meta($pid, 'bedrooms', true);
      $ba = get_post_meta($pid, 'bathrooms', true);
      $sl = get_post_meta($pid, 'sleeps', true);

      echo '<article style="border:1px solid #ddd;padding:12px;border-radius:10px;">';
      echo '<h3 style="margin:0 0 8px 0;">' . esc_html(get_the_title()) . '</h3>';
      echo '<div style="color:#666;font-size:13px;margin-bottom:8px;">' . esc_html(wp_trim_words(get_the_excerpt(), 20)) . '</div>';

      echo '<div style="margin-top:8px;color:#444;font-size:13px;">' . esc_html("Sleeps {$sl} • {$bd} bedrooms • {$ba} baths") . '</div>';

      if ($space) echo '<div style="margin-top:8px;"><strong>The space:</strong> ' . esc_html(wp_trim_words($space, 18)) . '</div>';
      if ($neigh) echo '<div style="margin-top:6px;"><strong>Neighbourhood:</strong> ' . esc_html(wp_trim_words($neigh, 18)) . '</div>';

      echo '</article>';
    }

    echo '</div>';

    wp_reset_postdata();
    return ob_get_clean();
  }

    public function render($atts = []) {
    $atts = shortcode_atts([
        'per_page' => 12,
    ], $atts);

    $checkin   = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : '';
    $checkout  = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : '';
    $guests    = isset($_GET['guests']) ? max(0, (int) $_GET['guests']) : 0;
    $bedrooms  = isset($_GET['bedrooms']) ? max(0, (int) $_GET['bedrooms']) : 0;
    $bathrooms = isset($_GET['bathrooms']) ? max(0, (float) $_GET['bathrooms']) : 0;

    // ✅ correct script URL (plugin root)
    if (defined('HCN_PLUGIN_URL')) {
        wp_enqueue_script('hcn-search', HCN_PLUGIN_URL . 'assets/hcn-search.js', [], '1.0.2', true);
    } else {
        // fallback if constant not defined
        wp_enqueue_script('hcn-search', plugin_dir_url(__DIR__) . 'assets/hcn-search.js', [], '1.0.2', true);
    }

    $nonce = wp_create_nonce('hcn_search_nonce');
    $ajax  = admin_url('admin-ajax.php');

    ob_start(); ?>

    <form class="hcn-search-form"
            data-ajax="<?php echo esc_attr($ajax); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>"
            data-per-page="<?php echo esc_attr((int)$atts['per_page']); ?>"
            style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">

        <div>
        <label>Check in</label><br>
        <input type="date" name="checkin" value="<?php echo esc_attr($checkin); ?>">
        </div>

        <div>
        <label>Check out</label><br>
        <input type="date" name="checkout" value="<?php echo esc_attr($checkout); ?>">
        </div>

        <div>
        <label>Guests (min)</label><br>
        <input type="number" name="guests" min="0" step="1" value="<?php echo esc_attr($guests); ?>" style="width:110px;">
        </div>

        <div>
        <label>Bedrooms (min)</label><br>
        <input type="number" name="bedrooms" min="0" step="1" value="<?php echo esc_attr($bedrooms); ?>" style="width:110px;">
        </div>

        <div>
        <label>Bathrooms (min)</label><br>
        <input type="number" name="bathrooms" min="0" step="0.5" value="<?php echo esc_attr($bathrooms); ?>" style="width:110px;">
        </div>

        <button type="submit">Search</button>
    </form>

    <div class="hcn-search-status" style="margin-top:10px;"></div>

    <div class="hcn-results-wrap" style="margin-top:10px;">
        <?php
        // ✅ Option A: ALWAYS show results (latest by default)
        echo $this->build_results_html(
            $checkin, $checkout, $guests, $bedrooms, $bathrooms, (int)$atts['per_page']
        );
        ?>
    </div>

    <?php
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