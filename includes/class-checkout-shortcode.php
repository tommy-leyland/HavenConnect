<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect Checkout
 *
 * Shortcode: [hcn_checkout]
 * AJAX actions:
 *   hcn_checkout_data        – load quote + fees + property details
 *   hcn_checkout_apply_promo – validate a promo code against Hostfully
 *   hcn_checkout_intent      – create Stripe PaymentIntent
 *   hcn_checkout_book        – create lead in Hostfully after payment
 */
class HavenConnect_Checkout_Shortcode {

    private $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;

        add_shortcode('hcn_checkout', [$this, 'render']);

        $ajax = ['hcn_checkout_data', 'hcn_checkout_apply_promo', 'hcn_checkout_intent', 'hcn_checkout_book'];
        foreach ($ajax as $action) {
            add_action("wp_ajax_{$action}",        [$this, str_replace('hcn_checkout_', 'ajax_', $action)]);
            add_action("wp_ajax_nopriv_{$action}", [$this, str_replace('hcn_checkout_', 'ajax_', $action)]);
        }
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function render($atts = []): string {
        $settings    = get_option('hcn_settings', []);
        $stripe_pub  = trim($settings['stripe_publishable_key'] ?? '');
        $nonce       = wp_create_nonce('hcn_checkout_nonce');
        $ajax_url    = admin_url('admin-ajax.php');

        $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(dirname(__FILE__)) . '/';

        wp_enqueue_script('hcn-checkout', $base . 'assets/hcn-checkout.js', [], '1.4.1', true);
        wp_enqueue_style('hcn-checkout',  $base . 'assets/hcn-checkout.css', [], '1.4.1');

        if ($stripe_pub) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        }

        ob_start(); ?>
<div id="hcn-checkout"
     data-ajax="<?php echo esc_attr($ajax_url); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-stripe-key="<?php echo esc_attr($stripe_pub); ?>">
  <div class="hcn-co-loading">
    <div class="hcn-co-spinner"></div>
    <p>Loading your booking…</p>
  </div>
</div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX: load quote + optional fees + property data
    // -------------------------------------------------------------------------

    public function ajax_data(): void {
        check_ajax_referer('hcn_checkout_nonce', 'nonce');

        $post_id  = (int)($_POST['post_id']  ?? 0);
        $checkin  = sanitize_text_field($_POST['checkin']  ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $guests   = max(1, (int)($_POST['guests'] ?? 1));

        if (!$post_id || !$checkin || !$checkout) {
            wp_send_json_error(['message' => 'Missing booking parameters.'], 400);
        }

        $uid   = (string) get_post_meta($post_id, '_havenconnect_uid', true);
        $title = get_the_title($post_id);
        $city  = (string) get_post_meta($post_id, 'city', true);
        $thumb = get_the_post_thumbnail_url($post_id, 'medium') ?: '';

        $hf_opts   = get_option('hcn_hostfully', []);
        if (!is_array($hf_opts)) $hf_opts = [];
        $apiKey    = trim($hf_opts['api_key']    ?? '');
        $agencyUid = trim($hf_opts['agency_uid'] ?? '');
        if (!$apiKey || !$agencyUid) {
            $legacy = get_option('havenconnect_options', []);
            if (is_array($legacy)) {
                $apiKey    = $apiKey    ?: trim($legacy['api_key']    ?? '');
                $agencyUid = $agencyUid ?: trim($legacy['agency_uid'] ?? '');
            }
        }

        // Quote
        $api   = $GLOBALS['havenconnect']['api'] ?? null;
        $quote = ($api && $uid) ? $api->calculate_quote($apiKey, $agencyUid, $uid, $checkin, $checkout, $guests) : [];

        $total    = $this->resolve_total($quote);
        $nights   = $this->diff_nights($checkin, $checkout);
        $currency = $quote['currency'] ?? 'GBP';

        // Line items from quote
        $line_items = $this->extract_line_items($quote, $total, $nights);

        // Optional fees for this property
        $fees = $this->get_optional_fees($apiKey, $uid);

        // Rental agreement URL (stored as post meta or from Hostfully settings)
        $rental_agreement_url = (string) get_post_meta($post_id, 'rental_agreement_url', true);

        // Booking mode for this property (instant|request|inquiry)
        $mode = $this->get_booking_mode($post_id);

        wp_send_json_success([
            'property' => [
                'post_id' => $post_id,
                'uid'     => $uid,
                'title'   => $title,
                'city'    => $city,
                'thumb'   => $thumb,
            ],
            'booking' => [
                'checkin'  => $checkin,
                'checkout' => $checkout,
                'nights'   => $nights,
                'guests'   => $guests,
            ],
            'quote' => [
                'total'      => $total,
                'currency'   => $currency,
                'line_items' => $line_items,
            ],
            'optional_fees'       => $fees,
            'rental_agreement_url'=> $rental_agreement_url,
            'agency_uid'          => $agencyUid,
            'booking_type'       => $mode['booking_type'],
            'payment_required'   => $mode['payment_required'],
            'lead_type'          => $mode['lead_type'],
            'lead_status'        => $mode['lead_status'],
        ]);
    }

    // -------------------------------------------------------------------------
    // AJAX: apply promo code
    // -------------------------------------------------------------------------

    public function ajax_apply_promo(): void {
        check_ajax_referer('hcn_checkout_nonce', 'nonce');

        $code     = strtoupper(sanitize_text_field($_POST['code']     ?? ''));
        $post_id  = (int)($_POST['post_id']  ?? 0);
        $checkin  = sanitize_text_field($_POST['checkin']  ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $guests   = max(1, (int)($_POST['guests'] ?? 1));

        if (!$code) wp_send_json_error(['message' => 'Please enter a promo code.'], 400);

        $uid = (string) get_post_meta($post_id, '_havenconnect_uid', true);
        $hf_opts   = get_option('hcn_hostfully', []);
        if (!is_array($hf_opts)) $hf_opts = [];
        $apiKey    = trim($hf_opts['api_key']    ?? '');
        $agencyUid = trim($hf_opts['agency_uid'] ?? '');
        if (!$apiKey || !$agencyUid) {
            $legacy = get_option('havenconnect_options', []);
            if (is_array($legacy)) {
                $apiKey    = $apiKey    ?: trim($legacy['api_key']    ?? '');
                $agencyUid = $agencyUid ?: trim($legacy['agency_uid'] ?? '');
            }
        }

        // Hostfully quote with promo code
        $api = $GLOBALS['havenconnect']['api'] ?? null;
        if (!$api) wp_send_json_error(['message' => 'API unavailable.'], 500);

        $quote = $api->calculate_quote_with_promo($apiKey, $agencyUid, $uid, $checkin, $checkout, $guests, $code);

        if (empty($quote)) {
            wp_send_json_error(['message' => 'Invalid promo code or it cannot be applied to this booking.']);
        }

        $total = $this->resolve_total($quote);
        if (!$total) {
            wp_send_json_error(['message' => 'Promo code could not be applied.']);
        }

        $nights     = $this->diff_nights($checkin, $checkout);
        $line_items = $this->extract_line_items($quote, $total, $nights);

        wp_send_json_success([
            'total'      => $total,
            'currency'   => $quote['currency'] ?? 'GBP',
            'line_items' => $line_items,
            'code'       => $code,
            'message'    => 'Promo code applied!',
        ]);
    }

    // -------------------------------------------------------------------------
    // AJAX: create Stripe PaymentIntent
    // -------------------------------------------------------------------------

    public function ajax_intent(): void {
        check_ajax_referer('hcn_checkout_nonce', 'nonce');

        $amount_pence = (int)($_POST['amount_pence'] ?? 0);
        $currency     = strtolower(sanitize_text_field($_POST['currency'] ?? 'gbp'));

        if ($amount_pence < 100) wp_send_json_error(['message' => 'Invalid amount.'], 400);

        $settings   = get_option('hcn_settings', []);
        $stripe_sec = trim($settings['stripe_secret_key'] ?? '');
        if (!$stripe_sec) wp_send_json_error(['message' => 'Stripe not configured.'], 500);

        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $stripe_sec,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'amount'   => $amount_pence,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => 'true'],
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Stripe error: ' . $response->get_error_message()], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200 || empty($body['client_secret'])) {
            error_log('[HCN checkout_intent] Stripe error: ' . wp_json_encode($body));
            wp_send_json_error(['message' => 'Could not initialise payment. Please try again.'], 500);
        }

        wp_send_json_success(['client_secret' => $body['client_secret']]);
    }

    // -------------------------------------------------------------------------
    // AJAX: create lead in Hostfully after successful payment
    // -------------------------------------------------------------------------

    public function ajax_book(): void {
        check_ajax_referer('hcn_checkout_nonce', 'nonce');

        $first      = sanitize_text_field($_POST['first_name']    ?? '');
        $last       = sanitize_text_field($_POST['last_name']     ?? '');
        $email      = sanitize_email($_POST['email']              ?? '');
        $phone      = sanitize_text_field($_POST['phone']         ?? '');
        $post_id    = (int)($_POST['post_id']   ?? 0);
        $checkin    = sanitize_text_field($_POST['checkin']       ?? '');
        $checkout   = sanitize_text_field($_POST['checkout']      ?? '');
        $guests     = max(1, (int)($_POST['guests']               ?? 1));
        // Guest breakdown (infants do NOT count towards total guest cap)
        $adults     = max(1, (int)($_POST['adults']               ?? $guests));
        $children   = max(0, (int)($_POST['children']             ?? 0));
        $infants    = max(0, (int)($_POST['infants']              ?? 0));
        $pets       = max(0, (int)($_POST['pets']                 ?? 0));
        $total      = (float)($_POST['total']                     ?? 0);
        $currency   = strtoupper(sanitize_text_field($_POST['currency'] ?? 'GBP'));
        $promo_code = strtoupper(sanitize_text_field($_POST['promo_code'] ?? ''));
        $agency_uid = sanitize_text_field($_POST['agency_uid']    ?? '');
        $intent_id  = sanitize_text_field($_POST['payment_intent'] ?? ''); // Stripe PI id
        $agreed     = !empty($_POST['rental_agreement']);

        $mode = $this->get_booking_mode($post_id);

        // Optional fee UIDs the guest selected
        $selected_fees = array_map('sanitize_text_field', (array)($_POST['selected_fees'] ?? []));

        if (!$first || !$last || !$email || !$checkin || !$checkout || !$post_id) {
            wp_send_json_error(['message' => 'Please complete all required fields.'], 400);
        }
        if (!$agreed) {
            wp_send_json_error(['message' => 'You must accept the rental agreement.'], 400);
        }

        // Validate guest totals: adults + children must not exceed the selected guest count.
        // Infants do not count towards the total.
        if (($adults + $children) > $guests) {
            wp_send_json_error(['message' => 'Adults + children cannot exceed the total number of guests.'], 400);
        }

        if ($mode['payment_required'] && !$intent_id) {
            wp_send_json_error(['message' => 'Payment could not be verified. Please try again.'], 400);
        }

        $uid = (string) get_post_meta($post_id, '_havenconnect_uid', true);
        $hf_opts   = get_option('hcn_hostfully', []);
        if (!is_array($hf_opts)) $hf_opts = [];
        $apiKey    = trim($hf_opts['api_key']    ?? '');
        if (!$apiKey) {
            $legacy = get_option('havenconnect_options', []);
            $apiKey = trim((is_array($legacy) ? $legacy['api_key'] : '') ?? '');
        }
        if (!$agency_uid) {
            $agency_uid = trim($hf_opts['agency_uid'] ?? '');
        }

        $api = $GLOBALS['havenconnect']['api'] ?? null;
        if (!$api) wp_send_json_error(['message' => 'API unavailable.'], 500);

        $lead_payload = [
            'agencyUid'       => $agency_uid,
            'propertyUid'     => $uid,

            // Dates (we send multiple variants for compatibility)
            'checkInDate'          => $checkin,
            'checkOutDate'         => $checkout,
            'checkInDateTime'      => $checkin . 'T00:00:00Z',
            'checkOutDateTime'     => $checkout . 'T00:00:00Z',
            'checkInLocalDate'     => $checkin,
            'checkOutLocalDate'    => $checkout,
            'checkInLocalDateTime' => $checkin . 'T00:00:00',
            'checkOutLocalDateTime'=> $checkout . 'T00:00:00',

            'guests'          => $guests,
            'numberOfGuests'  => $guests,

            // Basic guest fields (legacy + convenience)
            'guestFirstName'  => $first,
            'guestLastName'   => $last,
            'guestEmail'      => $email,
            'guestPhone'      => $phone,

            // Required by Hostfully for leads (including INQUIRY): guestInformation
            'guestInformation' => [
                'firstName'     => $first,
                'lastName'      => $last,
                'email'         => $email,
                'phoneNumber'   => $phone,
                'adultCount'    => $adults,
                'childrenCount' => $children,
                'infantCount'   => $infants,
                'petCount'      => $pets,
                // Default to GB unless you later add a UI field
                'countryCode'   => 'GB',
            ],

            'source'          => 'HOSTFULLY_DBS',

            // Booking mode mapping (instant|request|inquiry)
            'type'            => $mode['lead_type'],
            'status'          => $mode['lead_status'],

            'totalPrice'      => $total,
            'currency'        => $currency,
        ];
        if ($promo_code) $lead_payload['promoCode'] = $promo_code;
        if ($mode['payment_required']) {
            $lead_payload['stripePaymentIntentId'] = $intent_id;
        }

        $lead = $api->create_lead($apiKey, $lead_payload);

        error_log('[HCN checkout_book] create_lead response: ' . wp_json_encode($lead));

        $lead_uid = $lead['uid'] ?? $lead['lead']['uid'] ?? null;

        if (!$lead_uid) {
            $msg = $mode['payment_required']
                ? ('Booking could not be confirmed in our system. Please contact us quoting payment reference: ' . $intent_id)
                : 'Enquiry could not be submitted in our system. Please contact us with your dates.';
            wp_send_json_error([
                'message' => $msg,
                'debug'   => $lead,
            ], 500);
        }

        // Store basic booking record as WP post meta for reference
        update_post_meta($post_id, '_hcn_last_booking_lead_uid', $lead_uid);

        wp_send_json_success([
            'lead_uid'  => $lead_uid,
            'message'   => ($mode['payment_required'] ? 'Booking confirmed!' : 'Enquiry sent!'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolve_total(array $quote): float {
        foreach ([
            $quote['quote']['totalPrice']               ?? null,  // Hostfully v3.2 actual shape
            $quote['quote']['totalAmount']              ?? null,
            $quote['quote']['totalWithTaxes']           ?? null,
            $quote['totalPrice']                        ?? null,
            $quote['totalWithTaxes']                    ?? null,
            $quote['totalAmount']                       ?? null,
            $quote['totals']['totalWithTaxes']          ?? null,
        ] as $c) {
            if (is_numeric($c) && $c > 0) return (float)$c;
        }
        return 0.0;
    }

    private function diff_nights(string $a, string $b): int {
        $ta = strtotime($a); $tb = strtotime($b);
        return ($ta && $tb && $tb > $ta) ? (int)(($tb - $ta) / DAY_IN_SECONDS) : 0;
    }

    private function extract_line_items(array $quote, float $total, int $nights): array {
        // Try to pull structured line items from Hostfully quote response
        $items = [];

        $charges = $quote['charges'] ?? $quote['quote']['charges'] ?? $quote['quote']['fees'] ?? $quote['fees'] ?? [];
        if (is_array($charges) && !empty($charges)) {
            foreach ($charges as $c) {
                $name = $c['name'] ?? $c['type'] ?? $c['feeType'] ?? 'Fee';
                $amt  = $c['amount'] ?? $c['value'] ?? $c['total'] ?? null;
                if (is_numeric($amt)) {
                    $items[] = ['label' => $name, 'amount' => (float)$amt];
                }
            }
        }

        // Fallback: just show rent + total if no structured data
        if (empty($items) && $nights > 0) {
            $items[] = ['label' => "Accommodation ({$nights} nights)", 'amount' => $total];
        }

        return $items;
    }

    private function get_booking_mode(int $post_id): array {
        $raw = (string) get_post_meta($post_id, 'hcn_booking_type', true);
        $booking_type = in_array($raw, ['instant','request','inquiry'], true) ? $raw : 'instant';

        // Map to Hostfully lead type + status
        // instant  => BOOKING + BOOKED
        // request  => BOOKING_REQUEST + PENDING
        // inquiry  => INQUIRY + PENDING
        $map = [
            'instant' => ['lead_type' => 'BOOKING',         'lead_status' => 'BOOKED',           'payment_required' => true],
            'request' => ['lead_type' => 'BOOKING_REQUEST', 'lead_status' => 'ON_HOLD',          'payment_required' => true],
            'inquiry' => ['lead_type' => 'INQUIRY',         'lead_status' => 'PENDING',          'payment_required' => false],
        ]; 

        $m = $map[$booking_type];
        return [
            'booking_type' => $booking_type,
            'lead_type' => $m['lead_type'],
            'lead_status' => $m['lead_status'],
            'payment_required' => (bool) $m['payment_required'],
        ];
    }

    private function get_optional_fees(string $apiKey, string $propertyUid): array {
        if (!$apiKey || !$propertyUid) return [];

        $cache_key = 'hcn_fees_' . md5($propertyUid);
        $cached    = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $url  = "https://platform.hostfully.com/api/v3.2/fees?propertyUid={$propertyUid}";
        $res  = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['X-HOSTFULLY-APIKEY' => $apiKey],
        ]);

        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);
        $fees_raw = $body['fees'] ?? [];

        // Only return optional (non-mandatory) fees
        $optional = [];
        foreach ((array)$fees_raw as $f) {
            $required = $f['required'] ?? $f['mandatory'] ?? true;
            if ($required) continue;
            $optional[] = [
                'uid'   => $f['uid'] ?? '',
                'name'  => $f['name'] ?? 'Optional service',
                'type'  => $f['feeType'] ?? $f['type'] ?? '',
                'amount'=> (float)($f['amount'] ?? $f['value'] ?? 0),
            ];
        }

        set_transient($cache_key, $optional, HOUR_IN_SECONDS * 6);
        return $optional;
    }
}  