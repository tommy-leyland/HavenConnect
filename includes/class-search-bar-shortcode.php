<?php
if ( ! defined('ABSPATH') ) { exit; }
class HavenConnect_Search_Bar_Shortcode {
    public function __construct() {
        add_shortcode('hcn_search_bar', [$this, 'render']);
    }
    public function render($atts = []) {
        $atts = shortcode_atts([
            'action' => '',
            'ajax'   => '1',
        ], $atts, 'hcn_search_bar');
        $action = trim((string)$atts['action']);
        if (!$action) {
            $action = (is_singular() || is_page()) ? get_permalink() : home_url('/');
        }
        // Pre-fill from query string
        $checkin   = sanitize_text_field($_GET['checkin']   ?? '');
        $checkout  = sanitize_text_field($_GET['checkout']  ?? '');
        $guests    = sanitize_text_field($_GET['guests']    ?? '');
        $bedrooms  = sanitize_text_field($_GET['bedrooms']  ?? '');
        $bathrooms = sanitize_text_field($_GET['bathrooms'] ?? '');
        $location  = sanitize_text_field($_GET['location']  ?? '');
        $features  = sanitize_text_field($_GET['features']  ?? '');
        $policies  = sanitize_text_field($_GET['policies']  ?? '');
        $pets      = sanitize_text_field($_GET['pets']      ?? '');
        $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(dirname(__FILE__)); 
        wp_enqueue_style('hcn-search-bar',   $base . 'assets/hcn-search-bar.css',   [], '2.2.1');
        wp_enqueue_style('hcn-search-sheet', $base . 'assets/hcn-search-sheet.css', [], '2.2.1');
        wp_enqueue_script('hcn-search-sheet',$base . 'assets/hcn-search-sheet.js',  [], '2.2.1', true);
        wp_enqueue_style('hcn-filters',      $base . 'assets/hcn-filters.css',      [], '2.2.1');
        wp_enqueue_script('hcn-filters',     $base . 'assets/hcn-filters.js',       [], '2.2.2', true);
        $settings = get_option('hcn_settings', []);
        // Popular locations
        $popular_ids   = isset($settings['popular_locations']) && is_array($settings['popular_locations'])
                         ? array_map('intval', $settings['popular_locations']) : [];
        $popular_terms = [];
        if (!empty($popular_ids)) {
            $terms = get_terms(['taxonomy' => 'property_loc', 'include' => $popular_ids, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                $by_id = [];
                foreach ($terms as $t) $by_id[(int)$t->term_id] = $t;
                foreach ($popular_ids as $id) {
                    if (isset($by_id[$id])) {
                        $popular_terms[] = ['id' => (int)$by_id[$id]->term_id, 'name' => $by_id[$id]->name, 'slug' => $by_id[$id]->slug];
                    }
                }
            }
        }
        // Featured features
        $featured_ids   = isset($settings['featured_features']) && is_array($settings['featured_features'])
                          ? array_map('intval', $settings['featured_features']) : [];
        $featured_terms = [];
        if ($featured_ids) {
            $terms = get_terms(['taxonomy' => 'hcn_feature', 'include' => $featured_ids, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                $by_id = [];
                foreach ($terms as $t) $by_id[(int)$t->term_id] = $t;
                foreach ($featured_ids as $id) {
                    if (isset($by_id[$id])) {
                        $featured_terms[] = ['id' => $id, 'name' => $by_id[$id]->name, 'slug' => $by_id[$id]->slug];
                    }
                }
            }
        }
        // Policies (same pattern as features, taxonomy: hcn_policy)
        $policy_ids   = isset($settings['featured_policies']) && is_array($settings['featured_policies'])
                        ? array_map('intval', $settings['featured_policies']) : [];
        $policy_terms = [];
        if ($policy_ids) {
            $terms = get_terms(['taxonomy' => 'hcn_policy', 'include' => $policy_ids, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                $by_id = [];
                foreach ($terms as $t) $by_id[(int)$t->term_id] = $t;
                foreach ($policy_ids as $id) {
                    if (isset($by_id[$id])) {
                        $policy_terms[] = ['id' => $id, 'name' => $by_id[$id]->name, 'slug' => $by_id[$id]->slug];
                    }
                }
            }
        }
        // Capitalise location for display
        $location_display = $location ? ucwords(str_replace(['-', '_'], ' ', $location)) : '';
        wp_add_inline_script('hcn-search-sheet', 'window.HCN_SEARCH_BAR = ' . wp_json_encode([
            'ajax'             => ($atts['ajax'] === '1'),
            'action'           => $action,
            'checkin'          => $checkin,
            'checkout'         => $checkout,
            'guests'           => $guests,
            'bedrooms'         => $bedrooms,
            'bathrooms'        => $bathrooms,
            'location'         => $location,
            'features'         => $features,
            'policies'         => $policies,
            'pets'             => $pets,
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'locTax'           => 'property_loc',
            'popularLocations' => $popular_terms,
            'featuredFeatures' => $featured_terms,
            'featuredPolicies' => $policy_terms,
            'priceMin'         => 0,
            'priceMax'         => 14500,
        ]) . ';', 'before');
        ob_start();
        ?>
        <form class="hcn-searchbar" data-hcn-searchbar method="get" action="<?php echo esc_url($action); ?>">
            <!-- Hidden fields -->
            <input type="hidden" name="checkin"   value="<?php echo esc_attr($checkin);   ?>" data-hcn-checkin>
            <input type="hidden" name="checkout"  value="<?php echo esc_attr($checkout);  ?>" data-hcn-checkout>
            <input type="hidden" name="guests"    value="<?php echo esc_attr($guests);    ?>" data-hcn-guests>
            <input type="hidden" name="bedrooms"  value="<?php echo esc_attr($bedrooms);  ?>" data-hcn-bedrooms>
            <input type="hidden" name="bathrooms" value="<?php echo esc_attr($bathrooms); ?>" data-hcn-bathrooms>
            <input type="hidden" name="min_price" value="<?php echo esc_attr($_GET['min_price'] ?? ''); ?>">
            <input type="hidden" name="max_price" value="<?php echo esc_attr($_GET['max_price'] ?? ''); ?>">
            <input type="hidden" name="features"  value="<?php echo esc_attr($features);  ?>">
            <input type="hidden" name="policies"  value="<?php echo esc_attr($policies);  ?>">
            <input type="hidden" name="location"  value="<?php echo esc_attr($location);  ?>" data-hcn-location>
			<input type="hidden" name="date_tolerance"   data-hcn-tol-val value="0">
			<input type="hidden" name="date_flex_dur"    data-hcn-flex-dur>
			<input type="hidden" name="date_flex_months" data-hcn-flex-months-val>
            <!-- BAR -->
            <div class="hcn-searchbar__row" data-hcn-bar>
				<div class="hcn-searchbar__row_inner" data-hcn-bar>
					<!-- Where -->
					<button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="location">
						<span class="hcn-searchbar__icon">
							<svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M24.5 11.666C24.5 19.8327 14 26.8327 14 26.8327C14 26.8327 3.5 19.8327 3.5 11.666C3.5 8.88124 4.60625 6.21053 6.57538 4.24139C8.54451 2.27226 11.2152 1.16602 14 1.16602C16.7848 1.16602 19.4555 2.27226 21.4246 4.24139C23.3938 6.21053 24.5 8.88124 24.5 11.666Z" stroke="#B69068" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 15.166C15.933 15.166 17.5 13.599 17.5 11.666C17.5 9.73302 15.933 8.16602 14 8.16602C12.067 8.16602 10.5 9.73302 10.5 11.666C10.5 13.599 12.067 15.166 14 15.166Z" stroke="#B69068" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
						<span data-hcn-location-label><?php echo $location_display ? esc_html($location_display) : 'Add location'; ?></span>
					</button>
					<div class="hcn-searchbar__sep"></div>
					<!-- When -->
					<button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="dates">
						<span class="hcn-searchbar__icon">
							<svg width="27" height="30" viewBox="0 0 27 30" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18.8056 0.75V6.35M7.69444 0.75V6.35M0.75 11.95H25.75M3.52778 3.55H22.9722C24.5063 3.55 25.75 4.8036 25.75 6.35V25.95C25.75 27.4964 24.5063 28.75 22.9722 28.75H3.52778C1.99365 28.75 0.75 27.4964 0.75 25.95V6.35C0.75 4.8036 1.99365 3.55 3.52778 3.55Z" stroke="#B69068" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
						<span data-hcn-dates-label><?php echo ($checkin && $checkout) ? esc_html("$checkin → $checkout") : 'Add dates'; ?></span>
					</button>
					<div class="hcn-searchbar__sep"></div>
					<!-- Who -->
					<button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="guests">
						<span class="hcn-searchbar__icon">
							<svg width="22" height="24" viewBox="0 0 22 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.75 15.75H5.75C2.98858 15.75 0.75 17.9886 0.75 20.75V23.25H20.75V20.75C20.75 17.9886 18.5114 15.75 15.75 15.75Z" stroke="#B69068" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.75 10.75C13.5114 10.75 15.75 8.51142 15.75 5.75C15.75 2.98858 13.5114 0.75 10.75 0.75C7.98858 0.75 5.75 2.98858 5.75 5.75C5.75 8.51142 7.98858 10.75 10.75 10.75Z" stroke="#B69068" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
						<span data-hcn-guests-label><?php echo $guests ? esc_html($guests . ' guest' . ((int)$guests !== 1 ? 's' : '')) : 'Add guests'; ?></span>
					</button>
					<!-- Search button -->
					<button type="submit" class="hcn-searchbar__go" aria-label="Search">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="6" stroke="white"/><path d="M19 20L15 16" stroke="white" stroke-linecap="round"/></svg>
					</button>
				</div>
                <!-- Filters button -->
                <button type="button" class="hcn-searchbar__filters" data-hcn-open="filters">
                    <svg width="28" height="28" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3.75L7.5 3.75M7.5 3.75C7.5 4.57843 8.17157 5.25 9 5.25C9.82843 5.25 10.5 4.57843 10.5 3.75M7.5 3.75C7.5 2.92157 8.17157 2.25 9 2.25C9.82843 2.25 10.5 2.92157 10.5 3.75M10.5 3.75L15 3.75M3 9H12M12 9C12 9.82843 12.6716 10.5 13.5 10.5C14.3284 10.5 15 9.82843 15 9C15 8.17157 14.3284 7.5 13.5 7.5C12.6716 7.5 12 8.17157 12 9ZM6 14.25H15M6 14.25C6 13.4216 5.32843 12.75 4.5 12.75C3.67157 12.75 3 13.4216 3 14.25C3 15.0784 3.67157 15.75 4.5 15.75C5.32843 15.75 6 15.0784 6 14.25Z" stroke="black" stroke-linecap="round"/></svg>
                    Filters
                    <span class="hcn-dot" data-hcn-filters-dot style="display:none;"></span>
                </button>
            </div><!-- /.hcn-searchbar__row -->
            <!-- ============================================================
                 SHEET — Where / When / Who
                 Positioned absolutely relative to the bar segment that opens it
            ============================================================ -->
            <div class="hcn-sheet-overlay" data-hcn-sheet-overlay aria-hidden="true">
                <div class="hcn-sheet" data-hcn-sheet role="dialog" aria-modal="true">
                    <button type="button" class="hcn-sheet__close" data-hcn-sheet-close aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="13" y2="13"/><line x1="13" y1="1" x2="1" y2="13"/></svg>
                    </button>
                    <!-- WHERE accordion -->
                    <div class="hcn-acc" data-hcn-acc="location">
                        <button type="button" class="hcn-acc__head" data-hcn-acc-head>
                            <span class="hcn-acc__title">Where?</span>
                            <span class="hcn-acc__value-right">
                                <span data-hcn-acc-value><?php echo $location_display ? esc_html($location_display) : ''; ?></span>
                                <?php if ($location_display): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#b69068" stroke-width="1.8"><path stroke-linecap="round" d="M12 2C8.686 2 6 4.686 6 8c0 5.25 6 13 6 13s6-7.75 6-13c0-3.314-2.686-6-6-6z"/></svg>
                                <?php endif; ?>
                            </span>
                            <svg class="hcn-acc__arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                        <div class="hcn-acc__panel">
                            <div class="hcn-loc-search-wrap">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M16.5 16.5l4 4"/></svg>
                                <input class="hcn-loc-input" type="text" placeholder="Type a destination or property" data-hcn-loc-search autocomplete="off">
                            </div>
                            <div class="hcn-popular-label">POPULAR DESTINATIONS</div>
                            <div class="hcn-loc-list" data-hcn-popular-wrap></div>
                        </div>
                    </div>
                    <!-- WHEN accordion -->
                    <div class="hcn-acc" data-hcn-acc="dates">
                        <button type="button" class="hcn-acc__head" data-hcn-acc-head>
                            <span class="hcn-acc__title">When?</span>
                            <span class="hcn-acc__value-right">
                                <span data-hcn-acc-value><?php echo ($checkin && $checkout) ? esc_html("$checkin → $checkout") : ''; ?></span>
                                <?php if ($checkin && $checkout): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#b69068" stroke-width="1.8"><rect x="3" y="4" width="28" height="28" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                <?php endif; ?>
                            </span>
                            <svg class="hcn-acc__arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                        <div class="hcn-acc__panel">
                            <!-- Tabs: Dates / Flexible -->
                            <div class="hcn-date-tabs">
                                <button type="button" class="hcn-date-tab is-active" data-hcn-date-tab="dates">Dates</button>
                                <button type="button" class="hcn-date-tab" data-hcn-date-tab="flexible">Flexible</button>
                            </div>
                            <!-- DATES tab -->
                            <div class="hcn-date-panel" data-hcn-date-panel="dates">
                                <div class="hcn-dual-cal">
                                    <!-- Month 1 -->
                                    <div class="hcn-cal-col">
                                        <div class="hcn-cal-head">
                                            <button type="button" data-hcn-cal-prev aria-label="Previous">‹</button>
                                            <div class="hcn-cal-month" data-hcn-cal-month-a></div>
                                            <span></span>
                                        </div>
                                        <div class="hcn-cal-dow">
                                            <?php foreach (['M','T','W','T','F','S','S'] as $d): ?><div><?php echo $d; ?></div><?php endforeach; ?>
                                        </div>
                                        <div class="hcn-cal-grid" data-hcn-cal-grid-a></div>
                                    </div>
                                    <!-- Month 2 -->
                                    <div class="hcn-cal-col">
                                        <div class="hcn-cal-head">
                                            <span></span>
                                            <div class="hcn-cal-month" data-hcn-cal-month-b></div>
                                            <button type="button" data-hcn-cal-next aria-label="Next">›</button>
                                        </div>
                                        <div class="hcn-cal-dow">
                                            <?php foreach (['M','T','W','T','F','S','S'] as $d): ?><div><?php echo $d; ?></div><?php endforeach; ?>
                                        </div>
                                        <div class="hcn-cal-grid" data-hcn-cal-grid-b></div>
                                    </div>
                                </div>
                                <div class="hcn-cal-hint" data-hcn-cal-hint></div>
                                <!-- Flex tolerance pills -->
                                <div class="hcn-flex-tol">
                                    <button type="button" class="hcn-tol-pill is-active" data-hcn-tol="0">Exact dates</button>
                                    <button type="button" class="hcn-tol-pill" data-hcn-tol="1">± 1 day</button>
                                    <button type="button" class="hcn-tol-pill" data-hcn-tol="2">± 2 days</button>
                                    <button type="button" class="hcn-tol-pill" data-hcn-tol="3">± 3 days</button>
                                    <button type="button" class="hcn-tol-pill" data-hcn-tol="7">± 7 days</button>
                                    <button type="button" class="hcn-tol-pill" data-hcn-tol="14">± 14 days</button>
                                </div>
                            </div><!-- /.hcn-date-panel[dates] -->
                            <!-- FLEXIBLE tab -->
                            <div class="hcn-date-panel" data-hcn-date-panel="flexible" style="display:none;">
                                <div class="hcn-flex-duration-label">How long would you like to stay?</div>
                                <div class="hcn-flex-durations">
                                    <button type="button" class="hcn-dur-pill" data-hcn-dur="weekend">Weekend</button>
                                    <button type="button" class="hcn-dur-pill" data-hcn-dur="week">Week</button>
                                    <button type="button" class="hcn-dur-pill" data-hcn-dur="month">Month</button>
                                </div>
                                <div class="hcn-flex-go-label">When do you want to go?</div>
                                <div class="hcn-flex-months-wrap">
                                    <div class="hcn-flex-months" data-hcn-flex-months></div>
                                </div>
                            </div><!-- /.hcn-date-panel[flexible] -->
                        </div>
                    </div>
                    <!-- WHO accordion -->
                    <div class="hcn-acc" data-hcn-acc="guests">
                        <button type="button" class="hcn-acc__head" data-hcn-acc-head>
                            <span class="hcn-acc__title">Who?</span>
                            <span class="hcn-acc__value-right">
                                <span data-hcn-acc-value><?php echo $guests ? esc_html($guests . ' guest' . ((int)$guests !== 1 ? 's' : '')) : ''; ?></span>
                                <?php if ($guests): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#b69068" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.582-7 8-7s8 3 8 7"/></svg>
                                <?php endif; ?>
                            </span>
                            <svg class="hcn-acc__arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                        <div class="hcn-acc__panel">
                            <?php
                            $rows = [
                                ['key'=>'adults',   'label'=>'Adults',   'meta'=>'Ages 16 or above'],
                                ['key'=>'children', 'label'=>'Children', 'meta'=>'Ages 2 – 16'],
                                ['key'=>'infants',  'label'=>'Infants',  'meta'=>'Under 2'],
                                ['key'=>'pets',     'label'=>'Pets',     'meta'=>'A cleaning fee may apply'],
                            ];
                            foreach ($rows as $r): ?>
                            <div class="hcn-guest-row" data-hcn-guest="<?php echo esc_attr($r['key']); ?>">
                                <div>
                                    <div class="hcn-guest-label"><?php echo esc_html($r['label']); ?></div>
                                    <div class="hcn-guest-meta"><?php echo esc_html($r['meta']); ?></div>
                                </div>
                                <div class="hcn-stepper">
                                    <button type="button" data-hcn-step="-">–</button>
                                    <span class="hcn-stepper__n" data-hcn-step-out>0</span>
                                    <button type="button" data-hcn-step="+">+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="hcn-sheet__actions">
                        <button type="button" class="hcn-sheet__clear" data-hcn-sheet-clear>Clear all</button>
                        <button type="button" class="hcn-sheet__apply" data-hcn-sheet-apply>View homes</button>
                    </div>
                </div><!-- /.hcn-sheet -->
            </div><!-- /.hcn-sheet-overlay -->
            <!-- ============================================================
                 FILTERS POPUP
            ============================================================ -->
            <div class="hcn-filters-pop hcn-modal" data-hcn-modal="filters" aria-hidden="true" role="dialog" aria-modal="true">
                    <div class="hcn-filters-pop__head">
                        <span class="hcn-filters-pop__title">Filters</span>
                        <button type="button" class="hcn-filters__close" data-hcn-close aria-label="Close">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="13" y2="13"/><line x1="13" y1="1" x2="1" y2="13"/></svg>
                        </button>
                    </div>
                    <!-- Price per night -->
                    <div class="hcn-filter-section">
                        <div class="hcn-filter-section__head">
                            <span class="hcn-filter-section__title">Price per night</span>
                            <span class="hcn-filter-section__value" data-hcn-price-label>£0 to £14,500</span>
                        </div>
                        <div class="hcn-price-slider-wrap">
                            <input type="range" class="hcn-range-slider" min="0" max="14500" step="50" data-hcn-price-min-range value="0">
                            <input type="range" class="hcn-range-slider" min="0" max="14500" step="50" data-hcn-price-max-range value="14500">
                            <div class="hcn-range-track"><div class="hcn-range-fill" data-hcn-range-fill></div></div>
                        </div>
                    </div>
                    <!-- Bedrooms & Bathrooms -->
                    <div class="hcn-filter-section">
                        <div class="hcn-filter-section__row">
                            <span class="hcn-filter-section__label">Bedrooms</span>
                            <div class="hcn-stepper">
                                <button type="button" data-hcn-step="-" data-hcn-room="bedrooms">–</button>
                                <span class="hcn-stepper__n" data-hcn-bed-out>0</span>
                                <button type="button" data-hcn-step="+" data-hcn-room="bedrooms">+</button>
                            </div>
                            <span class="hcn-stepper-any" data-hcn-bed-any>Any</span>
                        </div>
                        <div class="hcn-filter-section__row">
                            <span class="hcn-filter-section__label">Bathrooms</span>
                            <div class="hcn-stepper">
                                <button type="button" data-hcn-step="-" data-hcn-room="bathrooms">–</button>
                                <span class="hcn-stepper__n" data-hcn-bath-out>0</span>
                                <button type="button" data-hcn-step="+" data-hcn-room="bathrooms">+</button>
                            </div>
                            <span class="hcn-stepper-any" data-hcn-bath-any>Any</span>
                        </div>
                    </div>
                    <!-- Features -->
                    <div class="hcn-filter-section">
                        <div class="hcn-filter-section__title">Features</div>
                        <div class="hcn-chips hcn-chips--features" data-hcn-feature-chips></div>
                    </div>
                    <!-- Policies (hardcoded: pet-friendly, allows-parties → written into features) -->
                    <div class="hcn-filter-section" data-hcn-pol-hard-section>
                        <div class="hcn-filter-section__title">Policies</div>
                        <div class="hcn-chips hcn-chips--policies" data-hcn-pol-hard-chips></div>
                    </div>
                    <!-- Dynamic policy chips from Settings (hidden if none configured) -->
                    <div class="hcn-filter-section" data-hcn-policies-section style="display:none;">
                        <div class="hcn-filter-section__title">More policies</div>
                        <div class="hcn-chips hcn-chips--policies" data-hcn-policy-chips></div>
                    </div>
                    <div class="hcn-filters__actions">
                        <button type="button" class="hcn-filters__clear" data-hcn-filters-clear>Clear all</button>
                        <button type="button" class="hcn-filters__apply" data-hcn-filters-apply>Apply</button>
                    </div>
            </div><!-- /.hcn-filters-pop -->
        </form>
        <?php
        return ob_get_clean();
    }
}