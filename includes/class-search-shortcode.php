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

    // -------------------------------------------------------------------------
    // Helpers: read & normalise date-mode params from $_GET or $_POST
    // -------------------------------------------------------------------------

    /**
     * Returns an array describing the date search mode:
     *   [ 'mode' => 'dates'|'flexible'|'none', ...mode-specific keys ]
     *
     * Dates mode keys:    checkin, checkout, tolerance (int days)
     * Flexible mode keys: flex_dur ('weekend'|'week'|'month'), flex_months (array of 'YYYY-MM')
     */
    private function parse_date_params(array $source): array {
        $checkin   = sanitize_text_field($source['checkin']           ?? '');
        $checkout  = sanitize_text_field($source['checkout']          ?? '');
        $tolerance = max(0, (int)($source['date_tolerance']           ?? 0));
        $flex_dur  = sanitize_text_field($source['date_flex_dur']     ?? '');
        $flex_raw  = sanitize_text_field($source['date_flex_months']  ?? '');

        $flex_months = array_values(array_filter(
            array_map('trim', explode(',', $flex_raw))
        ));

        // Flexible mode takes precedence when flex params are present
        if ($flex_dur && !empty($flex_months)) {
            return [
                'mode'        => 'flexible',
                'flex_dur'    => $flex_dur,
                'flex_months' => $flex_months,
            ];
        }

        if ($checkin && $checkout) {
            return [
                'mode'      => 'dates',
                'checkin'   => $checkin,
                'checkout'  => $checkout,
                'tolerance' => $tolerance,
            ];
        }

        return ['mode' => 'none'];
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------

    public function ajax_search() {
        check_ajax_referer('hcn_search_nonce', 'nonce');

        $source    = array_merge($_GET, $_POST);
        $location  = sanitize_text_field($source['location']  ?? '');
        $guests    = max(0, (int)($source['guests']           ?? 0));
        $bedrooms  = max(0, (int)($source['bedrooms']         ?? 0));
        $bathrooms = max(0, (float)($source['bathrooms']      ?? 0));
        $per_page  = max(1, (int)($source['per_page']         ?? 100));
        $min_price = isset($source['min_price']) ? (int)$source['min_price'] : 0;
        $max_price = isset($source['max_price']) ? (int)$source['max_price'] : 0;

        $features_csv  = sanitize_text_field($source['features'] ?? '');
        $feature_slugs = array_values(array_filter(array_map(
            'sanitize_title', array_map('trim', explode(',', $features_csv))
        )));

        $policies_csv  = sanitize_text_field($source['policies'] ?? '');
        $policy_slugs  = array_values(array_filter(array_map(
            'sanitize_title', array_map('trim', explode(',', $policies_csv))
        )));

        $date_params = $this->parse_date_params($source);

        $html = $this->build_results_html(
            $date_params, $location, $guests, $bedrooms, $bathrooms,
            $per_page, $min_price, $max_price, $feature_slugs, $policy_slugs
        );

        wp_send_json_success(['html' => $html]);
    }

    // -------------------------------------------------------------------------
    // Shortcode render
    // -------------------------------------------------------------------------

    public function render($atts = []) {
        $atts = shortcode_atts(['per_page' => 100], $atts);

        if (defined('HCN_PLUGIN_URL')) {
            $base = HCN_PLUGIN_URL;
        } else {
            $base = plugin_dir_url(__DIR__);
        }

        wp_enqueue_script('hcn-search', $base . 'assets/hcn-search.js', [], '1.2.3', true);
        wp_enqueue_style('hcn-tiles',   $base . 'assets/hcn-tiles.css', [], '1.2.3');

        $nonce   = wp_create_nonce('hcn_search_nonce');
        $ajaxUrl = admin_url('admin-ajax.php');

        $source       = $_GET;
        $location     = sanitize_text_field($source['location']  ?? '');
        $guests       = max(0, (int)($source['guests']           ?? 0));
        $bedrooms     = max(0, (int)($source['bedrooms']         ?? 0));
        $bathrooms    = max(0, (float)($source['bathrooms']      ?? 0));
        $min_price    = isset($source['min_price']) ? (int)$source['min_price'] : 0;
        $max_price    = isset($source['max_price']) ? (int)$source['max_price'] : 0;
        $features_csv = sanitize_text_field($source['features']  ?? '');
        $policies_csv = sanitize_text_field($source['policies']  ?? '');

        $feature_slugs = array_values(array_filter(array_map(
            'sanitize_title', array_map('trim', explode(',', $features_csv))
        )));
        $policy_slugs = array_values(array_filter(array_map(
            'sanitize_title', array_map('trim', explode(',', $policies_csv))
        )));

        $date_params = $this->parse_date_params($source);

        ob_start();
        ?>
        <div class="hcn-search-results" data-hcn-results-root>

            <form class="hcn-search-form hcn-search-form--hidden"
                  aria-hidden="true"
                  data-ajax="<?php echo esc_attr($ajaxUrl); ?>"
                  data-nonce="<?php echo esc_attr($nonce); ?>"
                  data-per-page="<?php echo esc_attr((int)$atts['per_page']); ?>">

                <input type="hidden" name="location"          value="<?php echo esc_attr($location); ?>">
                <input type="hidden" name="guests"            value="<?php echo esc_attr($guests); ?>">
                <input type="hidden" name="bedrooms"          value="<?php echo esc_attr($bedrooms); ?>">
                <input type="hidden" name="bathrooms"         value="<?php echo esc_attr($bathrooms); ?>">
                <input type="hidden" name="min_price"         value="<?php echo esc_attr($min_price); ?>">
                <input type="hidden" name="max_price"         value="<?php echo esc_attr($max_price); ?>">
                <input type="hidden" name="features"          value="<?php echo esc_attr($features_csv); ?>">
                <input type="hidden" name="policies"          value="<?php echo esc_attr($policies_csv); ?>">

                <!-- Always present so JS syncFormFromUrl can populate any of them -->
                <input type="hidden" name="checkin"          value="<?php echo esc_attr($date_params['mode']==='dates' ? $date_params['checkin'] : ''); ?>">
                <input type="hidden" name="checkout"         value="<?php echo esc_attr($date_params['mode']==='dates' ? $date_params['checkout'] : ''); ?>">
                <input type="hidden" name="date_tolerance"   value="<?php echo esc_attr($date_params['mode']==='dates' ? (string)$date_params['tolerance'] : '0'); ?>">
                <input type="hidden" name="date_flex_dur"    value="<?php echo esc_attr($date_params['mode']==='flexible' ? $date_params['flex_dur'] : ''); ?>">
                <input type="hidden" name="date_flex_months" value="<?php echo esc_attr($date_params['mode']==='flexible' ? implode(',', $date_params['flex_months']) : ''); ?>">

            </form>

            <div class="hcn-search-status" aria-live="polite"></div>
            <div class="hcn-results-wrap">
                <?php
                echo $this->build_results_html(
                    $date_params, $location, $guests, $bedrooms, $bathrooms,
                    (int)$atts['per_page'], $min_price, $max_price,
                    $feature_slugs, $policy_slugs
                );
                ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Main results builder — routes date logic by mode
    // -------------------------------------------------------------------------

    private function build_results_html(
        array  $date_params,
        string $location,
        int    $guests,
        int    $bedrooms,
        float  $bathrooms,
        int    $per_page,
        int    $min_price   = 0,
        int    $max_price   = 0,
        array  $feature_slugs = [],
        array  $policy_slugs  = []
    ): string {

        $args = [
            'post_type'      => 'hcn_property',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
        ];

        // ── Taxonomy filters ──────────────────────────────────────────────────
        $tax_query = [];

        if (trim($location) !== '') {
            $tax_query[] = [
                'taxonomy' => 'property_loc',
                'field'    => 'slug',
                'terms'    => [trim($location)],
            ];
        }

        if (!empty($feature_slugs)) {
            $tax_query[] = [
                'taxonomy' => 'hcn_feature',
                'field'    => 'slug',
                'terms'    => $feature_slugs,
                'operator' => 'AND',
            ];
        }

        if (!empty($policy_slugs)) {
            $tax_query[] = [
                'taxonomy' => 'hcn_policy',
                'field'    => 'slug',
                'terms'    => $policy_slugs,
                'operator' => 'AND',
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        // ── Availability filter by date mode ──────────────────────────────────
        $checkin_for_price  = '';
        $checkout_for_price = '';

        if ($date_params['mode'] === 'dates') {
            $checkin_for_price  = $date_params['checkin'];
            $checkout_for_price = $date_params['checkout'];

            $ids = $this->get_available_ids_with_tolerance(
                $date_params['checkin'],
                $date_params['checkout'],
                $date_params['tolerance']
            );

            if (empty($ids)) {
                return '<div class="hcn-empty">No properties available for those dates.</div>';
            }

            $args['post__in'] = $ids;
            $args['orderby']  = 'post__in';

        } elseif ($date_params['mode'] === 'flexible') {
            $ids = $this->get_available_ids_flexible(
                $date_params['flex_dur'],
                $date_params['flex_months']
            );

            if (empty($ids)) {
                return '<div class="hcn-empty">No properties available for those dates.</div>';
            }

            $args['post__in'] = $ids;
            $args['orderby']  = 'post__in';
        }

        // ── Price filter ──────────────────────────────────────────────────────
        if ($min_price > 0 || $max_price > 0) {
            $price_ids = $this->get_price_filtered_ids(
                $min_price, $max_price, $checkin_for_price, $checkout_for_price
            );

            if (empty($price_ids)) {
                return '<div class="hcn-empty">No properties match that price range.</div>';
            }

            if (!empty($args['post__in'])) {
                $args['post__in'] = array_values(array_intersect($args['post__in'], $price_ids));
                if (empty($args['post__in'])) {
                    return '<div class="hcn-empty">No properties match those filters.</div>';
                }
            } else {
                $args['post__in'] = $price_ids;
            }

            $args['orderby'] = 'post__in';
        }

        // ── Meta filters ──────────────────────────────────────────────────────
        $meta_query = [];

        if ($guests > 0) {
            $meta_query[] = ['key' => 'sleeps',    'value' => $guests,    'type' => 'NUMERIC', 'compare' => '>='];
        }
        if ($bedrooms > 0) {
            $meta_query[] = ['key' => 'bedrooms',  'value' => $bedrooms,  'type' => 'NUMERIC', 'compare' => '>='];
        }
        if ($bathrooms > 0) {
            $meta_query[] = ['key' => 'bathrooms', 'value' => $bathrooms, 'type' => 'NUMERIC', 'compare' => '>='];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        // ── Query & render ────────────────────────────────────────────────────
        $q = new WP_Query($args);

        if (!$q->have_posts()) {
            return '<div class="hcn-empty">No properties found.</div>';
        }

        $base       = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(__DIR__);
        $icon_user  = $base . 'assets/img/user2.jpg';
        $icon_beds  = $base . 'assets/img/beds.jpg';
        $icon_baths = $base . 'assets/img/baths.jpg';

        ob_start();
        echo '<div class="hcn-results--grid">';

        while ($q->have_posts()) {
            $q->the_post();
            $pid = (int) get_the_ID();

            $bd      = (int)   get_post_meta($pid, 'bedrooms',  true);
            $ba      = (float) get_post_meta($pid, 'bathrooms', true);
            $sl      = (int)   get_post_meta($pid, 'sleeps',    true);
            $featured = $this->get_card_image_url($pid);
            $gallery  = get_post_meta($pid, '_hcn_gallery_urls', true);
            if (!is_array($gallery)) $gallery = [];
            $gallery_count = count($gallery);

            $city    = (string) get_post_meta($pid, 'city',    true);
            $state   = (string) get_post_meta($pid, 'state',   true);
            $country = (string) get_post_meta($pid, 'country', true);
            $sub     = trim(implode(', ', array_filter([$city, $state, $country])));

            $pills = [];
            $terms = get_the_terms($pid, 'hcn_feature');
            if (is_array($terms)) {
                $terms = array_values($terms);
                for ($i = 0; $i < min(2, count($terms)); $i++) {
                    $pills[] = $terms[$i]->name;
                }
            }

            $badge     = (string) get_post_meta($pid, 'promo_badge',       true);
            $fav       = (string) get_post_meta($pid, 'guest_favourite',   true);
            $from_price = $this->get_from_price($pid, $checkin_for_price, $checkout_for_price);
            $title     = get_the_title($pid);
            $url       = get_permalink($pid);
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
                            <?php for ($d = 0; $d < min(5, $gallery_count); $d++): ?>
                                <span class="hcn-tile__dot<?php echo $d === 0 ? ' is-active' : ''; ?>"></span>
                            <?php endfor; ?>
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
                    <div class="hcn-tile__icons">
                        <span class="hcn-i"><span class="hcn-i__ic"><img src="<?php echo esc_url($icon_user); ?>" alt=""></span><?php echo esc_html($sl); ?></span>
                        <span class="hcn-i"><span class="hcn-i__ic"><img src="<?php echo esc_url($icon_beds); ?>" alt=""></span><?php echo esc_html($bd); ?></span>
                        <span class="hcn-i"><span class="hcn-i__ic"><img src="<?php echo esc_url($icon_baths); ?>" alt=""></span><?php echo esc_html($ba); ?></span>
                    </div>
                    <?php if (!empty($pills)): ?>
                        <div class="hcn-tile__pills">
                            <?php foreach ($pills as $pill): ?>
                                <span class="hcn-tile__pill"><?php echo esc_html($pill); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($from_price !== null && $from_price > 0): ?>
                        <div class="hcn-tile__price">From <strong>£<?php echo esc_html(number_format($from_price, 0)); ?></strong> per night</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Availability: exact dates + tolerance
    // -------------------------------------------------------------------------
    // Tolerance means the user picked e.g. 19 Mar → 21 Mar ± 3 days.
    // We find properties that have the required number of consecutive available
    // nights starting anywhere in the window [checkin - tol, checkout + tol].
    // The stay length is fixed (checkout - checkin nights); only the start date shifts.

    private function get_available_ids_with_tolerance(
        string $checkin,
        string $checkout,
        int    $tolerance
    ): array {

        $table = $this->db->prefix . 'hcn_availability';
        if (!$this->table_exists($table)) return [];

        $in_ts  = strtotime($checkin);
        $out_ts = strtotime($checkout);
        if (!$in_ts || !$out_ts || $out_ts <= $in_ts) return [];

        $nights = (int)(($out_ts - $in_ts) / DAY_IN_SECONDS);

        if ($tolerance === 0) {
            // Fast path: exact match (original logic)
            return $this->get_exact_available_ids($checkin, $checkout, $nights);
        }

        // Expanded window: try every possible start date within ± tolerance
        $window_start = gmdate('Y-m-d', $in_ts  - ($tolerance * DAY_IN_SECONDS));
        $window_end   = gmdate('Y-m-d', $out_ts + ($tolerance * DAY_IN_SECONDS));

        // Get all available nights in the expanded window, grouped by property
        // Then find properties that have ANY consecutive run of $nights available nights
        $sql = $this->db->prepare(
            "SELECT post_id, for_date
             FROM {$table}
             WHERE for_date >= %s
               AND for_date < %s
               AND unavailable = 0
             ORDER BY post_id, for_date",
            $window_start,
            $window_end
        );

        $rows = $this->db->get_results($sql);
        if (empty($rows)) return [];

        // Group dates by property
        $by_property = [];
        foreach ($rows as $row) {
            $by_property[$row->post_id][] = $row->for_date;
        }

        $matching_ids = [];
        foreach ($by_property as $post_id => $dates) {
            if ($this->has_consecutive_run($dates, $nights)) {
                $matching_ids[] = (int)$post_id;
            }
        }

        return $matching_ids;
    }

    private function get_exact_available_ids(string $checkin, string $checkout, int $nights): array {
        $table = $this->db->prefix . 'hcn_availability';
        $sql = $this->db->prepare(
            "SELECT post_id
             FROM {$table}
             WHERE for_date >= %s AND for_date < %s AND unavailable = 0
             GROUP BY post_id
             HAVING COUNT(*) = %d
             LIMIT 2000",
            $checkin,
            $checkout,
            $nights
        );
        return array_map('intval', $this->db->get_col($sql) ?: []);
    }

    // -------------------------------------------------------------------------
    // Availability: flexible dates
    // -------------------------------------------------------------------------
    // For each selected month, find properties that have a run of $nights
    // consecutive available nights starting anywhere within that calendar month.
    // A property qualifies if it matches ANY of the selected months.

    private function get_available_ids_flexible(string $flex_dur, array $flex_months): array {
        $table = $this->db->prefix . 'hcn_availability';
        if (!$this->table_exists($table)) return [];
        if (empty($flex_months)) return [];

        $nights = $this->flex_dur_to_nights($flex_dur);
        if ($nights < 1) return [];

        $matching_ids = [];

        foreach ($flex_months as $ym) {
            // Validate YYYY-MM format
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) continue;

            [$year, $month] = explode('-', $ym);
            $month_start = "{$year}-{$month}-01";
            // End of month + (nights - 1) days so a stay can start on the last few days
            $days_in_month = (int)date('t', strtotime($month_start));
            // We need to look slightly beyond month end so a stay starting near month-end can complete
            $window_end_ts = strtotime($month_start) + ($days_in_month + $nights - 1) * DAY_IN_SECONDS;
            $window_end    = gmdate('Y-m-d', $window_end_ts);

            $sql = $this->db->prepare(
                "SELECT post_id, for_date
                 FROM {$table}
                 WHERE for_date >= %s
                   AND for_date < %s
                   AND unavailable = 0
                 ORDER BY post_id, for_date",
                $month_start,
                $window_end
            );

            $rows = $this->db->get_results($sql);
            if (empty($rows)) continue;

            $by_property = [];
            foreach ($rows as $row) {
                $by_property[$row->post_id][] = $row->for_date;
            }

            foreach ($by_property as $post_id => $dates) {
                if ($this->has_consecutive_run($dates, $nights)) {
                    $matching_ids[(int)$post_id] = true; // dedup across months
                }
            }
        }

        return array_keys($matching_ids);
    }

    /**
     * Convert flex_dur string to number of nights required.
     * weekend = 2, week = 7, month = 28
     */
    private function flex_dur_to_nights(string $dur): int {
        switch ($dur) {
            case 'weekend': return 2;
            case 'week':    return 7;
            case 'month':   return 28;
            default:        return 0;
        }
    }

    /**
     * Check whether an array of date strings (Y-m-d, sorted ascending)
     * contains at least one run of $n consecutive dates.
     */
    private function has_consecutive_run(array $dates, int $n): bool {
        if (count($dates) < $n) return false;

        $timestamps = array_map('strtotime', $dates);
        $run = 1;

        for ($i = 1; $i < count($timestamps); $i++) {
            // Compare raw seconds: one calendar day = exactly 86400 seconds.
            // Avoid division to prevent float/int strict-comparison pitfalls.
            if (($timestamps[$i] - $timestamps[$i - 1]) === 86400) {
                $run++;
                if ($run >= $n) return true;
            } else {
                $run = 1;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Price filtering (unchanged from original)
    // -------------------------------------------------------------------------

    private function get_price_filtered_ids(int $min, int $max, string $checkin = '', string $checkout = ''): array {
        if ($min <= 0 && $max <= 0) return [];

        $table = $this->db->prefix . 'hcn_availability';
        if (!$this->table_exists($table)) return [];

        $where  = "unavailable = 0 AND price IS NOT NULL";
        $params = [];

        if ($checkin && $checkout) {
            $in  = strtotime($checkin);
            $out = strtotime($checkout);
            if ($in && $out && $out > $in) {
                $where   .= " AND for_date >= %s AND for_date < %s";
                $params[] = gmdate('Y-m-d', $in);
                $params[] = gmdate('Y-m-d', $out);
            }
        }

        $having_parts = [];
        if ($min > 0) { $having_parts[] = "MIN(price) >= %d"; $params[] = $min; }
        if ($max > 0) { $having_parts[] = "MIN(price) <= %d"; $params[] = $max; }
        $having = $having_parts ? ('HAVING ' . implode(' AND ', $having_parts)) : '';

        $sql      = "SELECT post_id FROM {$table} WHERE {$where} GROUP BY post_id {$having} LIMIT 5000";
        $prepared = $this->db->prepare($sql, $params);
        return array_map('intval', $this->db->get_col($prepared) ?: []);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function table_exists(string $table): bool {
        return $this->db->get_var(
            $this->db->prepare("SHOW TABLES LIKE %s", $table)
        ) === $table;
    }

    private function get_card_image_url(int $post_id): string {
        $thumb = (string) get_post_meta($post_id, '_hcn_featured_thumb_url', true);
        if ($thumb) return $thumb;
        $featured = (string) get_post_meta($post_id, '_hcn_featured_image_url', true);
        if ($featured) return $featured;
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) return (string)(wp_get_attachment_image_url($thumb_id, 'medium') ?: '');
        return '';
    }

    private function get_from_price(int $post_id, string $checkin = '', string $checkout = ''): ?float {
        $table = $this->db->prefix . 'hcn_availability';
        if (!$this->table_exists($table)) return null;

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
                return $min !== null ? (float)$min : null;
            }
        }

        $sql = $this->db->prepare(
            "SELECT MIN(price) FROM {$table}
             WHERE post_id = %d AND unavailable = 0 AND price IS NOT NULL",
            $post_id
        );
        $min = $this->db->get_var($sql);
        return $min !== null ? (float)$min : null;
    }
}