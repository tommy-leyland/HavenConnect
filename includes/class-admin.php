<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Admin {

  /**
   * Legacy single option key (your current settings were stored here).
   * If your old option key differs, update this constant to match your previous value.
   */
  const OPTION_KEY = 'havenconnect_options';

  // New split option keys
  const OPT_SETTINGS  = 'hcn_settings';
  const OPT_HOSTFULLY = 'hcn_hostfully';
  const OPT_LOGGIA    = 'hcn_loggia';

  /** @var HavenConnect_Property_Importer */
  private $importer;

  /** @var HavenConnect_Logger */
  private $logger;

  public function __construct($importer, $logger) {
    $this->importer = $importer;
    $this->logger   = $logger;

    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'settings_init']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
  }

  public function menu() {
    add_options_page(
      'HavenConnect',
      'HavenConnect',
      'manage_options',
      'havenconnect',
      [$this, 'render_settings_page']
    );
  }

  public function settings_init() {
    $this->maybe_migrate_options_v1();

    // Register new option keys (3 buckets)
    register_setting('hcn_settings_group',  self::OPT_SETTINGS,  [$this, 'sanitize_settings']);
    register_setting('hcn_hostfully_group', self::OPT_HOSTFULLY, [$this, 'sanitize_hostfully']);
    register_setting('hcn_loggia_group',    self::OPT_LOGGIA,    [$this, 'sanitize_loggia']);

    // Backward-compat: keep legacy registered so old installs don't fatal if other code still references it
    register_setting(self::OPTION_KEY, self::OPTION_KEY, function ($v) {
      return is_array($v) ? $v : [];
    });
  }

  public function enqueue_admin_assets($hook) {
    if ($hook !== 'settings_page_havenconnect') return;

    wp_enqueue_script(
      'hcn-admin-import',
      plugin_dir_url(__FILE__) . '../assets/hcn-admin-import.js',
      [],
      '1.3.10',
      true
    );

    // Provide HCN_IMPORT globals expected by hcn-admin-import.js
    wp_add_inline_script('hcn-admin-import', 'window.HCN_IMPORT = ' . wp_json_encode([
      'ajaxUrl'   => admin_url('admin-ajax.php'),
      'nonce'     => wp_create_nonce('hcn_import_nonce'),
      'editBase'  => admin_url('post.php?action=edit&post='),
    ]) . ';', 'before');

    // Keep your inline admin styles for now
    $css = "
      .hcn-tabs { margin: 16px 0; }
      .hcn-tabcontent { background:#fff; border:1px solid #ccd0d4; border-top:0; padding:16px; border-radius:0 0 8px 8px; }
      .hcn-card { border:1px solid #e5e7eb; border-radius:10px; padding:14px; background:#fff; margin:0 0 14px 0; }
      .hcn-grid { display:grid; grid-template-columns: 1fr; gap:12px; }
      @media (min-width: 1100px) { .hcn-grid { grid-template-columns: 1fr 1fr; } }
      .hcn-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
      .hcn-muted { opacity:.75; }
      .hcn-log { width:100%; min-height:260px; font-family: ui-monospace, Menlo, Consolas, monospace; }
      .hcn-small { font-size:12px; }
      .hcn-import-box { border:1px solid #e5e7eb; border-radius:10px; padding:14px; background:#fff; }
      .hcn-import-box h3 { margin-top:0; }
      .hcn-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
      .hcn-actions input[type=number]{ width:90px; }
      .hcn-actions input[type=text]{ min-width:260px; }
      .hcn-hr { border:0; border-top:1px solid #eee; margin:14px 0; }
      .hcn-kv { display:grid; grid-template-columns: 180px 1fr; gap:10px; align-items:center; }
      .hcn-kv label { font-weight:600; }
      .hcn-kv input[type=text]{ width:100%; max-width:520px; }
      .hcn-pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#f3f4f6; font-size:12px; }
    ";
    wp_add_inline_style('wp-admin', $css);
  }

  private function active_tab(): string {
    $tab = sanitize_text_field($_GET['tab'] ?? 'import');
    return in_array($tab, ['import', 'providers'], true) ? $tab : 'import';
  }

  /**
   * One-time migration: legacy OPTION_KEY => 3 new option keys
   */
  private function maybe_migrate_options_v1() {
    if (get_option('hcn_migrated_v1')) return;

    $old = get_option(self::OPTION_KEY);
    if (!is_array($old) || empty($old)) {
      update_option('hcn_migrated_v1', 1);
      return;
    }

    $settings  = get_option(self::OPT_SETTINGS, []);
    $hostfully = get_option(self::OPT_HOSTFULLY, []);
    $loggia    = get_option(self::OPT_LOGGIA, []);

    if (!is_array($settings))  $settings = [];
    if (!is_array($hostfully)) $hostfully = [];
    if (!is_array($loggia))    $loggia = [];

    // Google Maps -> settings
    if (!empty($old['google_maps_api_key']) && empty($settings['google_maps_api_key'])) {
      $settings['google_maps_api_key'] = $old['google_maps_api_key'];
    }

    // Hostfully -> hostfully
    if (!empty($old['api_key']) && empty($hostfully['api_key'])) {
      $hostfully['api_key'] = $old['api_key'];
    }
    if (!empty($old['agency_uid']) && empty($hostfully['agency_uid'])) {
      $hostfully['agency_uid'] = $old['agency_uid'];
    }

    // Loggia -> loggia
    foreach (['loggia_base_url','loggia_api_key','loggia_page_id','loggia_locale'] as $k) {
      if (!empty($old[$k]) && empty($loggia[$k])) {
        $loggia[$k] = $old[$k];
      }
    }

    update_option(self::OPT_SETTINGS,  $settings);
    update_option(self::OPT_HOSTFULLY, $hostfully);
    update_option(self::OPT_LOGGIA,    $loggia);

    update_option('hcn_migrated_v1', 1);
  }

  public function sanitize_settings($in) {
    $in = is_array($in) ? $in : [];

    $popular = isset($in['popular_locations']) ? (array)$in['popular_locations'] : [];
    $popular = array_values(array_filter(array_map('intval', $popular)));

    $featured = isset($in['featured_features']) ? (array)$in['featured_features'] : [];
    $featured = array_values(array_filter(array_map('intval', $featured)));

    return [
      'google_maps_api_key' => sanitize_text_field($in['google_maps_api_key'] ?? ''),
      'popular_locations'   => $popular,
      'featured_features'   => $featured,
    ];
  }

  public function sanitize_hostfully($in) {
    $in = is_array($in) ? $in : [];
    return [
      'api_key'      => sanitize_text_field($in['api_key'] ?? ''),
      'agency_uid'   => sanitize_text_field($in['agency_uid'] ?? ''),
      // import settings (used by admin UI only for now)
      'import_mode'  => in_array(($in['import_mode'] ?? 'all'), ['all','featured'], true) ? $in['import_mode'] : 'all',
      'import_limit' => max(1, (int)($in['import_limit'] ?? 10)),
    ];
  }

  public function sanitize_loggia($in) {
    $in = is_array($in) ? $in : [];
    return [
      'loggia_base_url' => esc_url_raw($in['loggia_base_url'] ?? ''),
      'loggia_api_key'  => sanitize_text_field($in['loggia_api_key'] ?? ''),
      'loggia_page_id'  => sanitize_text_field($in['loggia_page_id'] ?? ''),
      'loggia_locale'   => sanitize_text_field($in['loggia_locale'] ?? 'en'),
      // import settings (UI only for now)
      'import_limit'    => max(1, (int)($in['import_limit'] ?? 10)),
    ];
  }

  private function opt_settings(): array {
    $o = get_option(self::OPT_SETTINGS, []);
    return is_array($o) ? $o : [];
  }
  private function opt_hostfully(): array {
    $o = get_option(self::OPT_HOSTFULLY, []);
    return is_array($o) ? $o : [];
  }
  private function opt_loggia(): array {
    $o = get_option(self::OPT_LOGGIA, []);
    return is_array($o) ? $o : [];
  }

  
  private function render_term_multiselect(string $taxonomy, array $selected_ids, string $name_attr, int $size = 10): void {
    $terms = get_terms([
      'taxonomy'   => $taxonomy,
      'hide_empty' => false,
      'number'     => 500,
      'orderby'    => 'name',
      'order'      => 'ASC',
    ]);

    if (is_wp_error($terms)) {
      echo '<p class="hcn-muted hcn-small">Could not load terms for taxonomy: <code>' . esc_html($taxonomy) . '</code></p>';
      return;
    }

    echo '<select multiple size="' . (int)$size . '" name="' . esc_attr($name_attr) . '" style="min-width:320px; max-width:520px;">';
    foreach ($terms as $t) {
      $sel = in_array((int)$t->term_id, $selected_ids, true) ? ' selected' : '';
      echo '<option value="' . (int)$t->term_id . '"' . $sel . '>' . esc_html($t->name) . '</option>';
    }
    echo '</select>';
  }

public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $tab = $this->active_tab();

    echo '<div class="wrap">';
    echo '<h1>HavenConnect</h1>';

    echo '<h2 class="nav-tab-wrapper hcn-tabs">';
      echo '<a class="nav-tab ' . ($tab === 'import' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('options-general.php?page=havenconnect&tab=import')) . '">Import Settings</a>';
      echo '<a class="nav-tab ' . ($tab === 'providers' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('options-general.php?page=havenconnect&tab=providers')) . '">Providers</a>';
    echo '</h2>';

    echo '<div class="hcn-tabcontent">';
      if ($tab === 'providers') {
        $this->render_providers_tab();
      } else {
        $this->render_import_tab();
      }
    echo '</div>';

    echo '</div>';
  }

  private function render_providers_tab() {
    $settings  = $this->opt_settings();
    $hostfully = $this->opt_hostfully();
    $loggia    = $this->opt_loggia();

    echo '<div class="hcn-card">';
    echo '<p class="hcn-muted">These values are stored in WordPress options (no wp-config edits). Migration flag: <span class="hcn-pill">' . (get_option('hcn_migrated_v1') ? 'migrated' : 'pending') . '</span></p>';
    echo '</div>';

    echo '<div class="hcn-grid">';

      // Settings (Google Maps + Search config)
      echo '<div class="hcn-card">';
        echo '<h2>Settings</h2>';
        echo '<form method="post" action="options.php">';
          settings_fields('hcn_settings_group');

          // Google Maps
          echo '<div class="hcn-kv">';
            echo '<label for="hcn_gmaps">Google Maps API Key</label>';
            echo '<input id="hcn_gmaps" type="text" name="' . esc_attr(self::OPT_SETTINGS) . '[google_maps_api_key]" value="' . esc_attr($settings['google_maps_api_key'] ?? '') . '">';
          echo '</div>';
          echo '<p class="description hcn-small">Needs Maps JavaScript API enabled + billing in Google Cloud.</p>';

          echo '<hr class="hcn-hr">';

          // Popular Locations (property_loc) 
          $popular = isset($settings['popular_locations']) && is_array($settings['popular_locations']) ? array_map('intval', $settings['popular_locations']) : [];
          echo '<div class="hcn-kv">';
            echo '<label for="hcn_popular_locations">Popular locations</label>';
            echo '<div>';
              $this->render_term_multiselect('property_loc', $popular, self::OPT_SETTINGS . '[popular_locations][]', 8);
              echo '<p class="description hcn-small">Shown under “POPULAR DESTINATIONS” in the Location popup.</p>';
            echo '</div>'; 
          echo '</div>';

          echo '<hr class="hcn-hr">';

          // Featured Features (hcn_feature)
          $featured = isset($settings['featured_features']) && is_array($settings['featured_features']) ? array_map('intval', $settings['featured_features']) : [];
          echo '<div class="hcn-kv">';
            echo '<label for="hcn_featured_features">Featured features</label>';
            echo '<div>';
              $this->render_term_multiselect('hcn_feature', $featured, self::OPT_SETTINGS . '[featured_features][]', 8);
              echo '<p class="description hcn-small">Used to populate the “Features” chips in the Filters popup.</p>';
            echo '</div>';
          echo '</div>';

          submit_button('Save Settings');
        echo '</form>';
      echo '</div>';

      // Hostfully
      echo '<div class="hcn-card">';
        echo '<h2>Hostfully</h2>';
        echo '<form method="post" action="options.php">';
          settings_fields('hcn_hostfully_group');
          echo '<div class="hcn-kv">';
            echo '<label for="hcn_hf_key">API Key</label>';
            echo '<input id="hcn_hf_key" type="text" name="' . esc_attr(self::OPT_HOSTFULLY) . '[api_key]" value="' . esc_attr($hostfully['api_key'] ?? '') . '">';
            echo '<label for="hcn_hf_uid">Agency UID</label>';
            echo '<input id="hcn_hf_uid" type="text" name="' . esc_attr(self::OPT_HOSTFULLY) . '[agency_uid]" value="' . esc_attr($hostfully['agency_uid'] ?? '') . '">';
          echo '</div>';
          submit_button('Save Hostfully');
        echo '</form>';
      echo '</div>';

      // Loggia
      echo '<div class="hcn-card">';
        echo '<h2>Loggia</h2>';
        echo '<form method="post" action="options.php">';
          settings_fields('hcn_loggia_group');
          echo '<div class="hcn-kv">';
            echo '<label for="hcn_loggia_base">Base URL</label>';
            echo '<input id="hcn_loggia_base" type="text" name="' . esc_attr(self::OPT_LOGGIA) . '[loggia_base_url]" value="' . esc_attr($loggia['loggia_base_url'] ?? '') . '" placeholder="https://...">';
            echo '<label for="hcn_loggia_key">API Key</label>';
            echo '<input id="hcn_loggia_key" type="text" name="' . esc_attr(self::OPT_LOGGIA) . '[loggia_api_key]" value="' . esc_attr($loggia['loggia_api_key'] ?? '') . '">';
            echo '<label for="hcn_loggia_page">Page ID</label>';
            echo '<input id="hcn_loggia_page" type="text" name="' . esc_attr(self::OPT_LOGGIA) . '[loggia_page_id]" value="' . esc_attr($loggia['loggia_page_id'] ?? '') . '">';
            echo '<label for="hcn_loggia_locale">Locale</label>';
            echo '<input id="hcn_loggia_locale" type="text" name="' . esc_attr(self::OPT_LOGGIA) . '[loggia_locale]" value="' . esc_attr($loggia['loggia_locale'] ?? 'en') . '" placeholder="en">';
          echo '</div>';
          submit_button('Save Loggia');
        echo '</form>';

        echo '<hr class="hcn-hr">';
        echo '<h3>Loggia Connection Test</h3>';
        echo '<p class="hcn-muted hcn-small">Calls the Loggia list endpoint and reports whether it responds.</p>';
        echo '<div class="hcn-actions">';
          echo '<button class="button" id="hcn-loggia-test-btn">Test Loggia Connection</button>';
        echo '</div>';
        echo '<pre id="hcn-loggia-test-output" class="hcn-small" style="margin-top:10px; white-space:pre-wrap;"></pre>';

      echo '</div>';

    echo '</div>';
  }

  private function render_import_tab() {
    $hostfully = $this->opt_hostfully();
    $loggia    = $this->opt_loggia();

    $has_loggia = !empty($loggia['loggia_base_url']) && !empty($loggia['loggia_api_key']) && !empty($loggia['loggia_page_id']);

    echo '<p class="hcn-muted">Run imports via AJAX queue (safe for large batches). Use “First N” for quick checks.</p>';

    echo '<div class="hcn-grid">';

      // Import All (combined)
      echo '<div class="hcn-import-box hcn-card" data-provider="all">';
        echo '<h3>Import All Properties (Hostfully + Loggia)</h3>';
        echo '<p class="hcn-small hcn-muted">Builds a combined queue and runs through it.</p>';
        echo '<div class="hcn-actions">';
          echo '<label class="hcn-small">Run first</label>';
          echo '<input type="number" min="1" value="10" data-role="first-n">';
          echo '<button class="button" data-action="run-first">Run First N</button>';
          echo '<button class="button button-primary" data-action="run-all">Run All</button>';
        echo '</div>';
      echo '</div>';

      // Hostfully box
      echo '<div class="hcn-import-box hcn-card" data-provider="hostfully">';
        echo '<h3>Import Hostfully Properties</h3>';
        echo '<div class="hcn-actions">';
          echo '<label class="hcn-small">Run first</label>';
          echo '<input type="number" min="1" value="' . esc_attr((int)($hostfully['import_limit'] ?? 10)) . '" data-role="first-n">';
          echo '<button class="button" data-action="run-first">Run First N</button>';
          echo '<button class="button button-primary" data-action="run-all">Run All</button>';
        echo '</div>';

        echo '<div class="hcn-actions" style="margin-top:10px;">';
          echo '<label class="hcn-small">Mode</label>';
          echo '<select data-role="mode">';
            $mode = $hostfully['import_mode'] ?? 'all';
            echo '<option value="all"' . selected($mode, 'all', false) . '>All</option>';
            echo '<option value="featured"' . selected($mode, 'featured', false) . '>Featured</option>';
          echo '</select>';
        echo '</div>';

        echo '<hr class="hcn-hr">';
        echo '<div class="hcn-actions">';
          echo '<label class="hcn-small">Test single UID</label>';
          echo '<input type="text" placeholder="Hostfully property UID…" data-role="single-id">';
          echo '<button class="button" data-action="run-single">Run Single</button>';
        echo '</div>';
      echo '</div>';

      // Loggia box
      echo '<div class="hcn-import-box hcn-card" data-provider="loggia"' . ($has_loggia ? '' : ' style="opacity:.6;"') . '>';
        echo '<h3>Import Loggia Properties</h3>';

        if (!$has_loggia) {
          echo '<p class="hcn-muted hcn-small">Loggia is not configured yet (missing Base URL / API Key / Page ID). Import buttons are disabled.</p>';
        } else {
          echo '<p class="hcn-muted hcn-small">Loggia import is multi-endpoint; start with small batches.</p>';
        }

        echo '<div class="hcn-actions">';
          echo '<label class="hcn-small">Run first</label>';
          echo '<input type="number" min="1" value="' . esc_attr((int)($loggia['import_limit'] ?? 10)) . '" data-role="first-n" ' . ($has_loggia ? '' : 'disabled') . '>';
          echo '<button class="button" data-action="run-first" ' . ($has_loggia ? '' : 'disabled') . '>Run First N</button>';
          echo '<button class="button button-primary" data-action="run-all" ' . ($has_loggia ? '' : 'disabled') . '>Run All</button>';
        echo '</div>';

        echo '<hr class="hcn-hr">';
        echo '<div class="hcn-actions">';
          echo '<label class="hcn-small">Test single ID</label>';
          echo '<input type="text" placeholder="Loggia property ID…" data-role="single-id" ' . ($has_loggia ? '' : 'disabled') . '>';
          echo '<button class="button" data-action="run-single" ' . ($has_loggia ? '' : 'disabled') . '>Run Single</button>';
        echo '</div>';

        echo '<hr class="hcn-hr">';
        echo '<h4 style="margin:0 0 8px;">Sync availability/pricing</h4>';
        echo '<div class="hcn-actions">';
          echo '<input type="date" data-role="avail-from" ' . ($has_loggia ? '' : 'disabled') . '>';
          echo '<input type="date" data-role="avail-to" ' . ($has_loggia ? '' : 'disabled') . '>';
          echo '<button class="button" data-action="sync-avail" ' . ($has_loggia ? '' : 'disabled') . '>Sync Availability</button>';
        echo '</div>';
      echo '</div>';

    echo '</div>'; // grid

    echo '<div class="hcn-card">';
      echo '<h3>Imported Items</h3>';
      echo '<div id="hcn-imported-list"><em>Nothing imported yet in this session.</em></div>';
    echo '</div>';

    echo '<div class="hcn-card">';
      echo '<h3>Live Log</h3>';
      echo '<p class="hcn-muted hcn-small">Updates automatically while an import is running.</p>';
      echo '<textarea id="hcn-log" class="hcn-log" readonly></textarea>';
    echo '</div>';

    echo '<div class="hcn-card">';
      echo '<h3>Ping AJAX</h3>';
      echo '<div class="hcn-actions">';
        echo '<button class="button" id="hcn-ping-btn">Ping AJAX</button>';
        echo '<span id="hcn-ping-out" class="hcn-small hcn-muted"></span>';
      echo '</div>';
    echo '</div>';
  }
}