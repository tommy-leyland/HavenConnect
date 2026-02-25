<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Search_Bar_Shortcode {

  public function __construct() {
    add_shortcode('hcn_search_bar', [$this, 'render']);
  }

  public function render($atts = []) {
    $atts = shortcode_atts([
      // Where to submit (fallback). If blank, uses current page.
      'action' => '',
      // If 1, auto-updates URL + triggers hcn:search-updated on changes.
      'ajax'   => '1',
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

    wp_enqueue_style('hcn-search-bar', $base . 'assets/hcn-search-bar.css', [], '1.1.0');
    wp_enqueue_script('hcn-search-bar', $base . 'assets/hcn-search-bar.js', [], '1.1.0', true);

    wp_add_inline_script('hcn-search-bar', 'window.HCN_SEARCH_BAR = ' . wp_json_encode([
      'ajax'      => ($atts['ajax'] === '1'),
      'action'    => $action,
      'checkin'   => $checkin,
      'checkout'  => $checkout,
      'guests'    => $guests,
      'bedrooms'  => $bedrooms,
      'bathrooms' => $bathrooms,
      'location'  => $location,
    ]) . ';', 'before');

    ob_start();
    ?>
    <form class="hcn-searchbar" method="get" action="<?php echo esc_url($action); ?>" data-hcn-searchbar>
      <div class="hcn-searchbar__main">

        <!-- Location (free text for now) -->
        <label class="hcn-sf hcn-sf--location">
          <span class="hcn-sf__icon">📍</span>
          <input
            class="hcn-sf__input"
            type="text"
            name="location"
            placeholder="Add location"
            value="<?php echo esc_attr($location); ?>"
            autocomplete="off"
            data-hcn-location
          />
        </label>

        <div class="hcn-sf__sep" aria-hidden="true"></div>

        <!-- Dates field opens modal -->
        <button type="button" class="hcn-sf hcn-sf--dates" data-hcn-open="dates">
          <span class="hcn-sf__icon">📅</span>
          <span class="hcn-sf__text" data-hcn-dates-label>Add dates</span>
        </button>

        <!-- real fields used by results/map/search -->
        <input type="hidden" name="checkin"  value="<?php echo esc_attr($checkin); ?>"  data-hcn-checkin>
        <input type="hidden" name="checkout" value="<?php echo esc_attr($checkout); ?>" data-hcn-checkout>

        <div class="hcn-sf__sep" aria-hidden="true"></div>

        <!-- Guests (simple number) -->
        <label class="hcn-sf hcn-sf--guests">
          <span class="hcn-sf__icon">👤</span>
          <input
            class="hcn-sf__input"
            type="number"
            min="1"
            step="1"
            name="guests"
            placeholder="Add guests"
            value="<?php echo esc_attr($guests); ?>"
            data-hcn-guests
          />
        </label>

        <!-- Hidden min filters (single source of truth, prevents duplicates) -->
        <input type="hidden" name="bedrooms" value="<?php echo esc_attr($bedrooms); ?>" data-hcn-bedrooms>
        <input type="hidden" name="bathrooms" value="<?php echo esc_attr($bathrooms); ?>" data-hcn-bathrooms>

        <!-- Submit -->
        <button type="submit" class="hcn-searchbar__go" aria-label="Search">
          🔍
        </button>
      </div>

      <!-- Filters button -->
      <button type="button" class="hcn-filters" data-hcn-open="filters">
        <span class="hcn-filters__icon">☰</span>
        <span>Filters</span>
        <span class="hcn-filters__dot" data-hcn-filters-dot></span>
      </button>

      <!-- Filters modal -->
      <div class="hcn-modal" data-hcn-modal="filters" aria-hidden="true">
        <div class="hcn-modal__backdrop" data-hcn-close></div>
        <div class="hcn-modal__panel" role="dialog" aria-modal="true" aria-label="Filters">
          <div class="hcn-modal__head">
            <div class="hcn-modal__title">Filters</div>
            <button type="button" class="hcn-modal__x" data-hcn-close aria-label="Close">×</button>
          </div>

          <div class="hcn-modal__body">
            <!-- NOTE: No name="" here (prevents duplicate query params) -->
            <div class="hcn-field">
              <label>Guests (min)</label>
              <input type="number" min="1" step="1" value="<?php echo esc_attr($guests); ?>" data-hcn-filter-guests>
            </div>
            <div class="hcn-field">
              <label>Bedrooms (min)</label>
              <input type="number" min="0" step="1" value="<?php echo esc_attr($bedrooms); ?>" data-hcn-filter-bedrooms>
            </div>
            <div class="hcn-field">
              <label>Bathrooms (min)</label>
              <input type="number" min="0" step="1" value="<?php echo esc_attr($bathrooms); ?>" data-hcn-filter-bathrooms>
            </div>
          </div>

          <div class="hcn-modal__foot">
            <button type="button" class="hcn-btn hcn-btn--ghost" data-hcn-clear-filters>Clear</button>
            <button type="button" class="hcn-btn hcn-btn--primary" data-hcn-apply-filters>Apply</button>
          </div>
        </div>
      </div>

      <!-- Dates modal (single range calendar) -->
      <div class="hcn-modal" data-hcn-modal="dates" aria-hidden="true">
        <div class="hcn-modal__backdrop" data-hcn-close></div>
        <div class="hcn-modal__panel" role="dialog" aria-modal="true" aria-label="Dates">
          <div class="hcn-modal__head">
            <div class="hcn-modal__title">Add dates</div>
            <button type="button" class="hcn-modal__x" data-hcn-close aria-label="Close">×</button>
          </div>

          <div class="hcn-modal__body">
            <div class="hcn-cal">
              <div class="hcn-cal__head">
                <button type="button" class="hcn-cal__nav" data-hcn-cal-prev aria-label="Previous month">‹</button>
                <div class="hcn-cal__month" data-hcn-cal-month></div>
                <button type="button" class="hcn-cal__nav" data-hcn-cal-next aria-label="Next month">›</button>
              </div>

              <div class="hcn-cal__dow">
                <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
              </div>

              <div class="hcn-cal__grid" data-hcn-cal-grid></div>
              <div class="hcn-cal__hint" data-hcn-cal-hint>Select check-in, then check-out</div>
            </div>
          </div>

          <div class="hcn-modal__foot">
            <button type="button" class="hcn-btn hcn-btn--ghost" data-hcn-clear-dates>Clear</button>
            <button type="button" class="hcn-btn hcn-btn--primary" data-hcn-apply-dates>Apply</button>
          </div>
        </div>
      </div>

    </form>
    <?php
    return ob_get_clean();
  }
}