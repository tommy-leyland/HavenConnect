<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect Property Calendar
 *
 * Provides:
 *  - [hcn_property_calendar] shortcode  (renders the inline Flatpickr calendar + booking panel)
 *  - wp_ajax_hcn_calendar_data          (returns availability JSON for a property)
 */
class HavenConnect_Property_Calendar {

    /** @var wpdb */
    private $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;

        add_shortcode('hcn_property_calendar', [$this, 'render']);

        add_action('wp_ajax_hcn_calendar_data',        [$this, 'ajax_calendar_data']);
        add_action('wp_ajax_nopriv_hcn_calendar_data', [$this, 'ajax_calendar_data']);

        add_action('wp_ajax_hcn_calendar_quote',        [$this, 'ajax_calendar_quote']);
        add_action('wp_ajax_nopriv_hcn_calendar_quote', [$this, 'ajax_calendar_quote']);
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function render($atts = []): string {
        $atts = shortcode_atts([
            'post_id'  => 0,
            'months'   => 3,
        ], $atts);

        $post_id = $atts['post_id'] ? (int)$atts['post_id'] : get_the_ID();

        // get_the_ID() can return false/0 in page builders — try the global $post as fallback
        if (!$post_id) {
            global $post;
            $post_id = isset($post->ID) ? (int)$post->ID : 0;
        }

        if (!$post_id) {
            return '<!-- hcn_property_calendar: could not determine post ID. Pass post_id="123" to the shortcode. -->';
        }

        // Pull property meta needed for guest pricing
        $property_uid = (string) get_post_meta($post_id, '_havenconnect_uid', true);
        $max_guests   = max(1, (int) get_post_meta($post_id, 'sleeps',      true) ?: 1);
        $base_guests  = max(1, (int) get_post_meta($post_id, 'base_guests', true) ?: 1);

        $settings     = get_option('hcn_settings', []);
        $checkout_url = trim($settings['checkout_page_url'] ?? '/checkout/') ?: '/checkout/';
        $base  = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(__DIR__) . '/';
        $nonce = wp_create_nonce('hcn_calendar_nonce');

        wp_enqueue_script(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js',
            [],
            '4.6.18',
            true
        );
        wp_enqueue_style(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css',
            [],
            '4.6.18'
        );

        wp_enqueue_script(
            'hcn-calendar',
            $base . 'assets/hcn-calendar.js',
            ['flatpickr'],
            '1.0.3',
            true
        );
        wp_enqueue_style(
            'hcn-calendar',
            $base . 'assets/hcn-calendar.css',
            ['flatpickr'],
            '1.0.3'
        );
        wp_add_inline_script('hcn-calendar', 'window.hcnCheckoutUrl = ' . wp_json_encode($checkout_url) . ';', 'before');

        ob_start(); ?>
<div class="hcn-cal-wrap"
     data-post-id="<?php echo esc_attr($post_id); ?>"
     data-ajax="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-months="<?php echo esc_attr((int)$atts['months']); ?>"
     data-property-uid="<?php echo esc_attr($property_uid); ?>"
     data-max-guests="<?php echo esc_attr($max_guests); ?>"
     data-base-guests="<?php echo esc_attr($base_guests); ?>"
     data-checkout-url="<?php echo esc_attr($checkout_url); ?>">

    <?php if ($max_guests > 1): ?>
    <div class="hcn-cal-guest-row">
      <label class="hcn-cal-guest-label" for="hcn-cal-guests-<?php echo esc_attr($post_id); ?>">Guests</label>
      <div class="hcn-cal-guest-stepper">
        <button type="button" class="hcn-cal-guest-btn" data-dir="-" aria-label="Fewer guests">−</button>
        <input type="number"
               id="hcn-cal-guests-<?php echo esc_attr($post_id); ?>"
               class="hcn-cal-guests"
               value="<?php echo esc_attr($base_guests); ?>"
               min="1"
               max="<?php echo esc_attr($max_guests); ?>"
               readonly>
        <button type="button" class="hcn-cal-guest-btn" data-dir="+" aria-label="More guests">+</button>
      </div>
      <span class="hcn-cal-guest-max">Max <?php echo esc_html($max_guests); ?></span>
    </div>
    <?php endif; ?>

    <div class="hcn-cal-layout">

        <!-- Left: inline Flatpickr mounts here -->
        <div class="hcn-cal-picker">
            <input type="text" class="hcn-cal-input" placeholder="Select dates" readonly>
            <div class="hcn-cal-loading" aria-live="polite">Loading availability…</div>
        </div>

        <!-- Right: booking summary panel -->
        <div class="hcn-cal-panel" aria-live="polite">
            <div class="hcn-cal-panel__inner hcn-cal-panel--empty">
                <p class="hcn-cal-panel__hint">Select your check-in date on the calendar to get started.</p>
            </div>
        </div>

    </div><!-- /.hcn-cal-layout -->

    <div class="hcn-cal-notice" role="alert" aria-live="assertive" hidden></div>

</div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX: return availability data for the calendar
    // -------------------------------------------------------------------------

    public function ajax_calendar_data(): void {
        check_ajax_referer('hcn_calendar_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        if (!$post_id || get_post_type($post_id) !== 'hcn_property') {
            wp_send_json_error(['message' => 'Invalid property.']);
        }

        // How many months ahead to return (default 6)
        $months  = isset($_POST['months']) ? max(1, min(18, (int)$_POST['months'])) : 6;
        $from    = gmdate('Y-m-d');
        $until   = gmdate('Y-m-d', strtotime("+{$months} months"));

        $table   = $this->db->prefix . 'hcn_availability';
        $exists  = $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            wp_send_json_error(['message' => 'Availability table not found.']);
        }

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT for_date, price, unavailable, checkin, checkout, min_stay, max_stay
                 FROM {$table}
                 WHERE post_id = %d
                   AND for_date >= %s
                   AND for_date <  %s
                 ORDER BY for_date ASC",
                $post_id,
                $from,
                $until
            ),
            ARRAY_A
        );

        // Build a keyed map: "YYYY-MM-DD" => { price, unavailable, checkin, checkout, min_stay, max_stay }
        $days = [];
        foreach ((array)$rows as $r) {
            $days[$r['for_date']] = [
                'p'   => $r['price']       !== null ? (float)$r['price']    : null,  // nightly price
                'u'   => (int)$r['unavailable'],                                      // 1 = blocked
                'ci'  => $r['checkin']     !== null ? (int)$r['checkin']    : null,  // 1 = checkin allowed, 0 = not
                'co'  => $r['checkout']    !== null ? (int)$r['checkout']   : null,  // 1 = checkout allowed
                'mn'  => $r['min_stay']    !== null ? (int)$r['min_stay']   : null,
                'mx'  => $r['max_stay']    !== null ? (int)$r['max_stay']   : null,
            ];
        }

        // Property-level defaults from post meta (fallback when row doesn't specify)
        $default_min_stay    = (int) get_post_meta($post_id, 'min_stay',        true) ?: 1;
        $default_checkin_day = get_post_meta($post_id, 'checkin_day_of_week', true); // e.g. "Friday" or ""

        wp_send_json_success([
            'days'             => $days,
            'from'             => $from,
            'until'            => $until,
            'default_min_stay' => $default_min_stay,
            'checkin_day'      => $default_checkin_day,
            'currency'         => '£',
        ]);
    }

    /**
     * Quote endpoint — uses hcn_calendar_nonce so the calendar JS doesn't need
     * the map nonce. Proxies to the Hostfully Quote API with guest count support.
     */
    public function ajax_calendar_quote(): void {
        check_ajax_referer('hcn_calendar_nonce', 'nonce');

        $uid      = sanitize_text_field($_POST['uid']      ?? '');
        $checkin  = sanitize_text_field($_POST['checkin']  ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $guests   = max(1, (int)($_POST['guests'] ?? 1));

        if (!$uid || !$checkin || !$checkout) {
            wp_send_json_error(['message' => 'Missing parameters'], 400);
        }

        $in     = strtotime($checkin);
        $out    = strtotime($checkout);
        $nights = ($in && $out && $out > $in) ? (int)(($out - $in) / DAY_IN_SECONDS) : 0;
        if ($nights <= 0) wp_send_json_error(['message' => 'Invalid date range'], 400);

        $cache_key = 'hcn_calq_' . md5($uid . '|' . $checkin . '|' . $checkout . '|' . $guests);
        $cached    = get_transient($cache_key);
        if (is_array($cached)) { wp_send_json_success($cached); return; }

        $hf_opts   = get_option('hcn_hostfully', []);
        if (!is_array($hf_opts)) $hf_opts = [];
        $apiKey    = trim($hf_opts['api_key']    ?? '');
        $agencyUid = trim($hf_opts['agency_uid'] ?? '');

        if (!$apiKey || !$agencyUid) {
            $legacy = get_option('havenconnect_options', []);
            if (is_array($legacy)) {
                if (!$apiKey)    $apiKey    = trim($legacy['api_key']    ?? '');
                if (!$agencyUid) $agencyUid = trim($legacy['agency_uid'] ?? '');
            }
        }

        if (!$apiKey || !$agencyUid) {
            wp_send_json_error(['message' => 'Hostfully API not configured'], 500); 
        }

        if (empty($GLOBALS['havenconnect']['api']) || !method_exists($GLOBALS['havenconnect']['api'], 'calculate_quote')) {
            wp_send_json_error(['message' => 'API client unavailable'], 500);
        }

        $quote = $GLOBALS['havenconnect']['api']->calculate_quote($apiKey, $agencyUid, $uid, $checkin, $checkout, $guests);

        $total = null;
        foreach ([
            $quote['quote']['totalPrice']               ?? null,  // Hostfully v3.2 actual shape
            $quote['quote']['totalAmount']              ?? null,
            $quote['quote']['totalWithTaxes']           ?? null,
            $quote['totalPrice']                        ?? null,
            $quote['totalWithTaxes']                    ?? null,
            $quote['totalAmount']                       ?? null,
            $quote['totals']['totalWithTaxes']          ?? null,
            $quote['totals']['totalAmount']             ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && $candidate > 0) { $total = (float)$candidate; break; }
        }

        if (!$total) wp_send_json_error(['message' => 'Quote returned no total'], 422);

        $result = [
            'total'    => round($total, 2),
            'perNight' => round($total / $nights, 2),
            'nights'   => $nights,
            'guests'   => $guests,
            'currency' => (string)($quote['currency'] ?? $quote['quote']['currency'] ?? 'GBP'),
        ];

        set_transient($cache_key, $result, 20 * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

}