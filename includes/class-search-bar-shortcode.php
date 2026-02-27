<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Search_Bar_Shortcode {

  public function __construct() {
    add_shortcode('hcn_search_bar', [$this, 'render']);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      'action' => '',
      'ajax'   => '1', // 1 = update URL + emit hcn:search-updated
    ], $atts, 'hcn_search_bar');

    $action = trim((string)$atts['action']);
    if (!$action) {
      $action = (is_singular() || is_page()) ? get_permalink() : home_url('/');
    }

    // Pre-fill from query string
    $checkin   = sanitize_text_field($_GET['checkin'] ?? '');
    $checkout  = sanitize_text_field($_GET['checkout'] ?? '');
    $guests    = sanitize_text_field($_GET['guests'] ?? '');
    $bedrooms  = sanitize_text_field($_GET['bedrooms'] ?? '');
    $bathrooms = sanitize_text_field($_GET['bathrooms'] ?? '');
    $location  = sanitize_text_field($_GET['location'] ?? '');

    $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(dirname(__FILE__));

    // Existing CSS for bar (keep)
    wp_enqueue_style('hcn-search-bar', $base . 'assets/hcn-search-bar.css', [], '1.2.0');

    // New sheet assets
    wp_enqueue_style('hcn-search-sheet', $base . 'assets/hcn-search-sheet.css', [], '1.0.0');
    wp_enqueue_script('hcn-search-sheet', $base . 'assets/hcn-search-sheet.js', [], '1.0.0', true);
	
	$settings = get_option('hcn_settings', []);
	$popular_ids = isset($settings['popular_locations']) && is_array($settings['popular_locations'])
	  ? array_map('intval', $settings['popular_locations'])
	  : [];

	$popular_terms = [];
	if (!empty($popular_ids)) {
	  $terms = get_terms([
		'taxonomy' => 'property_loc',
		'include'  => $popular_ids,
		'hide_empty' => false,
	  ]);
	  if (!is_wp_error($terms)) {
		// Keep the same order as selected IDs
		$by_id = [];
		foreach ($terms as $t) $by_id[(int)$t->term_id] = $t;
		foreach ($popular_ids as $id) {
		  if (isset($by_id[$id])) {
			$popular_terms[] = [
			  'id'   => (int)$by_id[$id]->term_id,
			  'name' => $by_id[$id]->name,
			  'slug' => $by_id[$id]->slug,
			];
		  }
		}
	  }
	}

    // Keep config object name the same
    wp_add_inline_script('hcn-search-sheet', 'window.HCN_SEARCH_BAR = ' . wp_json_encode([
		'ajax'      => ($atts['ajax'] === '1'),
		'action'    => $action,
		'checkin'   => $checkin,
		'checkout'  => $checkout,
		'guests'    => $guests,
		'bedrooms'  => $bedrooms,
		'bathrooms' => $bathrooms,
		'location'  => $location,
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'locTax'  => 'property_loc',
		'popularLocations' => $popular_terms,
    ]) . ';', 'before');

    ob_start();
    ?>
    <form class="hcn-searchbar" data-hcn-searchbar method="get" action="<?php echo esc_url($action); ?>">
      <!-- Hidden fields that drive URL + results -->
      <input type="hidden" name="checkin"   value="<?php echo esc_attr($checkin); ?>"  data-hcn-checkin>
      <input type="hidden" name="checkout"  value="<?php echo esc_attr($checkout); ?>" data-hcn-checkout>
      <input type="hidden" name="guests"    value="<?php echo esc_attr($guests); ?>"   data-hcn-guests>
      <input type="hidden" name="bedrooms"  value="<?php echo esc_attr($bedrooms); ?>" data-hcn-bedrooms>
      <input type="hidden" name="bathrooms" value="<?php echo esc_attr($bathrooms); ?>" data-hcn-bathrooms>

      <!-- Visible "location" value is stored in this input so it goes into querystring -->
      <input type="hidden" name="location" value="<?php echo esc_attr($location); ?>" data-hcn-location>

      <!-- BAR -->
      <div class="hcn-searchbar__row">
        <button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="location">
          <span class="hcn-searchbar__icon">
			<img class="hcn-searchbar__icon-img" src="<?php echo esc_url($base . 'assets/img/location.jpg'); ?>" alt="">
		  </span>
          <span data-hcn-location-label><?php echo $location ? esc_html($location) : 'Add location'; ?></span>
        </button>

        <div class="hcn-searchbar__sep"></div>

        <button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="dates">
          <span class="hcn-searchbar__icon">
			<img class="hcn-searchbar__icon-img" src="<?php echo esc_url($base . 'assets/img/dates.jpg'); ?>" alt="">
		  </span>
          <span data-hcn-dates-label><?php echo ($checkin && $checkout) ? esc_html("$checkin → $checkout") : 'Add dates'; ?></span>
        </button> 

        <div class="hcn-searchbar__sep"></div>

        <button type="button" class="hcn-searchbar__seg" data-hcn-sheet-open="guests">
          <span class="hcn-searchbar__icon">
			<img class="hcn-searchbar__icon-img" src="<?php echo esc_url($base . 'assets/img/user.jpg'); ?>" alt="">
		  </span>
          <span data-hcn-guests-label><?php echo $guests ? esc_html($guests) : 'Add guests'; ?></span>
        </button>

        <button type="submit" class="hcn-searchbar__go" aria-label="Search">
			<img class="hcn-searchbar__icon-img" src="<?php echo esc_url($base . 'assets/img/button.jpg'); ?>" alt="">
		</button>

        <button type="button" class="hcn-searchbar__filters" data-hcn-open="filters">
          <span class="hcn-searchbar__filters-ic">
			<img class="hcn-searchbar__icon-img" src="<?php echo esc_url($base . 'assets/img/filter.jpg'); ?>" alt="">
		  </span> Filters
          <span class="hcn-dot" data-hcn-filters-dot style="display:none;"></span>
        </button>
      </div>

      <!-- SHEET OVERLAY -->
      <div class="hcn-sheet-overlay" data-hcn-sheet-overlay aria-hidden="true">
        <div class="hcn-sheet" role="dialog" aria-modal="true" aria-label="Search options">
          <button type="button" class="hcn-sheet__close" data-hcn-sheet-close aria-label="Close">×</button>

          <!-- WHERE -->
          <div class="hcn-acc" data-hcn-acc="location">
            <button type="button" class="hcn-acc__head" data-hcn-acc-head>
              <div>
                <div class="hcn-acc__title">Where?</div>
                <div class="hcn-acc__value" data-hcn-acc-value><?php echo $location ? esc_html($location) : 'Add location'; ?></div>
              </div>
              <div>▾</div>
            </button>
            <div class="hcn-acc__panel">
              <input class="hcn-loc-input" type="text" placeholder="Type a destination or property" data-hcn-loc-search>
              <div class="hcn-loc-list">
                <div class="hcn-muted" style="font-size:11px; opacity:.6; margin:8px 0 6px;">POPULAR DESTINATIONS</div>
                <div class="hcn-loc-item" data-hcn-loc-pick="Anglesey">📍 Anglesey, North Wales</div>
                <div class="hcn-loc-item" data-hcn-loc-pick="Cotswolds">📍 Cotswolds, England</div>
                <div class="hcn-loc-item" data-hcn-loc-pick="Lake District">📍 Lake District, England</div>
              </div>
            </div>
          </div>

          <!-- WHEN -->
          <div class="hcn-acc" data-hcn-acc="dates">
            <button type="button" class="hcn-acc__head" data-hcn-acc-head>
              <div>
                <div class="hcn-acc__title">When?</div>
                <div class="hcn-acc__value" data-hcn-acc-value><?php echo ($checkin && $checkout) ? esc_html("$checkin → $checkout") : 'Add dates'; ?></div>
              </div>
              <div>▾</div>
            </button>
            <div class="hcn-acc__panel">
              <div class="hcn-cal-head">
                <button type="button" data-hcn-cal-prev aria-label="Previous month">‹</button>
                <div class="hcn-cal-month" data-hcn-cal-month></div>
                <button type="button" data-hcn-cal-next aria-label="Next month">›</button>
              </div>

              <div class="hcn-muted" data-hcn-cal-hint style="font-size:12px; opacity:.7; margin-top:6px;"></div>

              <div class="hcn-cal-grid" data-hcn-cal-grid></div>
            </div>
          </div>

          <!-- WHO -->
          <div class="hcn-acc" data-hcn-acc="guests">
            <button type="button" class="hcn-acc__head" data-hcn-acc-head>
              <div>
                <div class="hcn-acc__title">Who?</div>
                <div class="hcn-acc__value" data-hcn-acc-value><?php echo $guests ? esc_html($guests) : 'Add guests'; ?></div>
              </div>
              <div>▾</div>
            </button>
            <div class="hcn-acc__panel">
              <?php
              $rows = [
                ['key'=>'adults', 'label'=>'Adults',  'meta'=>'Ages 16 or above'],
                ['key'=>'children','label'=>'Children','meta'=>'Ages 2 – 16'],
                ['key'=>'infants', 'label'=>'Infants', 'meta'=>'Under 2'],
                ['key'=>'pets',    'label'=>'Pets',    'meta'=>'A cleaning fee may apply'],
              ];
              foreach ($rows as $r):
              ?>
              <div class="hcn-guest-row" data-hcn-guest="<?php echo esc_attr($r['key']); ?>">
                <div>
                  <div style="font-weight:700;"><?php echo esc_html($r['label']); ?></div>
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
        </div>
      </div>

      <!-- KEEP your existing Filters modal markup here if you already had it (unchanged) -->
      <?php /* If your current version includes filters modal HTML, keep it below */ ?>
    </form>
    <?php
    return ob_get_clean();
  }
}