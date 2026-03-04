<?php
if (!defined('ABSPATH')) exit;

/**
 * [hcn_results_layout] shortcode
 *
 * Renders:
 *   ┌──────────────────────────────────────────────┐
 *   │  TOOLBAR  (100%) — count · sort · map toggle │
 *   ├────────────────────────┬─────────────────────┤
 *   │  [hcn_property_search] │  [hcn_property_map] │
 *   │        ~60%            │        ~40%          │
 *   └────────────────────────┴─────────────────────┘
 *
 * When "Hide map" is clicked JS adds `.hcn-layout--map-hidden` to the wrapper:
 *   - map column gets display:none
 *   - results column expands to 100%
 * No Elementor dependency whatsoever.
 *
 * Attributes:
 *   map_height  — passed through to [hcn_property_map] (default: 100%)
 *   per_page    — passed through to [hcn_property_search] (default: 100)
 */
class HavenConnect_Results_Layout_Shortcode {

    public function __construct() {
        add_shortcode('hcn_results_layout', [$this, 'render']);
    }

    public function render($atts = []): string {
        $atts = shortcode_atts([
            'map_height' => '100%',
            'per_page'   => 100,
        ], $atts);

        $base = defined('HCN_PLUGIN_URL') ? HCN_PLUGIN_URL : plugin_dir_url(__DIR__);

        wp_enqueue_style(
            'hcn-results-layout',
            $base . 'assets/hcn-results-layout.css',
            [],
            '1.0.1'
        );
        wp_enqueue_script(
            'hcn-results-layout',
            $base . 'assets/hcn-results-layout.js',
            [],
            '1.0.1',
            true
        );

        $search_html = do_shortcode(
            '[hcn_property_search per_page="' . (int)$atts['per_page'] . '"]'
        );
        $map_html = do_shortcode(
            '[hcn_property_map height="' . esc_attr($atts['map_height']) . '"]'
        );

        ob_start(); ?>
<div class="hcn-layout" data-hcn-layout>

    <!-- Toolbar: count + sort + map toggle -->
    <div class="hcn-layout__toolbar">
        <span class="hcn-layout__count" data-hcn-layout-count>Loading…</span>

        <div class="hcn-layout__toolbar-right">
            <div class="hcn-layout__sort-wrap">
                <label for="hcn-layout-sort" class="hcn-layout__sort-label">Sort by</label>
                <select id="hcn-layout-sort" class="hcn-layout__sort" data-hcn-layout-sort>
                    <option value="default">Default</option>
                    <option value="price-asc">Price: low to high</option>
                    <option value="price-desc">Price: high to low</option>
                    <option value="name-asc">Name: A – Z</option>
                </select>
            </div>

            <button type="button" class="hcn-layout__map-btn" data-hcn-layout-map-toggle>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
                <span data-hcn-layout-map-label>Hide map</span>
            </button>
        </div>
    </div>

    <!-- Content row -->
    <div class="hcn-layout__row">
        <div class="hcn-layout__results" data-hcn-layout-results>
            <?php echo $search_html; ?> 
        </div>
        <div class="hcn-layout__map" data-hcn-layout-map>
            <?php echo $map_html; ?>
        </div>
    </div>

</div>
        <?php
        return ob_get_clean();
    }
}