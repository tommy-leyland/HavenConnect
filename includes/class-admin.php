<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Admin {

  public const OPTION_KEY = 'havenconnect_settings';

  private $importer;
  private $logger;

  public function __construct($importer, $logger) {
    $this->importer = $importer;
    $this->logger   = $logger;

    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'settings_init']);
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
    register_setting(self::OPTION_KEY, self::OPTION_KEY, function ($v) {
      return [
        'api_key'             => sanitize_text_field($v['api_key'] ?? ''),
        'agency_uid'          => sanitize_text_field($v['agency_uid'] ?? ''),
        'google_maps_api_key' => sanitize_text_field($v['google_maps_api_key'] ?? ''),
        'loggia_base_url' => esc_url_raw($v['loggia_base_url'] ?? ''),
        'loggia_api_key'  => sanitize_text_field($v['loggia_api_key'] ?? ''),
        'loggia_page_id'  => sanitize_text_field($v['loggia_page_id'] ?? ''),
        'loggia_locale'   => sanitize_text_field($v['loggia_locale'] ?? 'en'),
      ];
    });

    add_settings_section(
      'hcn_section_main',
      'HavenConnect Settings',
      function () {
        echo '<p>Enter your Hostfully credentials and Google Maps API key.</p>';
      },
      'havenconnect'
    );

    add_settings_field('hcn_api_key', 'Hostfully API Key', [$this, 'field_api_key'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_agency_uid', 'Agency UID', [$this, 'field_agency_uid'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_google_maps_key', 'Google Maps API Key', [$this, 'field_google_maps_api_key'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_base_url', 'Loggia Base URL', [$this, 'field_loggia_base_url'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_api_key',  'Loggia API Key',  [$this, 'field_loggia_api_key'],  'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_page_id',  'Loggia Page ID',  [$this, 'field_loggia_page_id'],  'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_locale',   'Loggia Locale',   [$this, 'field_loggia_locale'],   'havenconnect', 'hcn_section_main');
  }

  public function field_api_key() {
    $opts = get_option(self::OPTION_KEY, []);
    $val  = esc_attr($opts['api_key'] ?? '');
    echo "<input type='text' name='" . self::OPTION_KEY . "[api_key]' value='{$val}' class='regular-text' />";
  }

  public function field_agency_uid() {
    $opts = get_option(self::OPTION_KEY, []);
    $val  = esc_attr($opts['agency_uid'] ?? '');
    echo "<input type='text' name='" . self::OPTION_KEY . "[agency_uid]' value='{$val}' class='regular-text' />";
  }

  public function field_google_maps_api_key() {
    $opts = get_option(self::OPTION_KEY, []);
    $val  = esc_attr($opts['google_maps_api_key'] ?? '');
    echo "<input type='text' name='" . self::OPTION_KEY . "[google_maps_api_key]' value='{$val}' class='regular-text' />";
    echo "<p class='description'>Needs Maps JavaScript API enabled + billing in Google Cloud.</p>";
  }

  public function field_loggia_base_url() {
  $opts = get_option(self::OPTION_KEY, []);
  $val = esc_attr($opts['loggia_base_url'] ?? '');
  echo "<input type='url' name='".self::OPTION_KEY."[loggia_base_url]' value='{$val}' class='regular-text' placeholder='https://api.example.com'>";
}

public function field_loggia_api_key() {
  $opts = get_option(self::OPTION_KEY, []);
  $val = esc_attr($opts['loggia_api_key'] ?? '');
  echo "<input type='text' name='".self::OPTION_KEY."[loggia_api_key]' value='{$val}' class='regular-text' autocomplete='off'>";
}

public function field_loggia_page_id() {
  $opts = get_option(self::OPTION_KEY, []);
  $val = esc_attr($opts['loggia_page_id'] ?? '');
  echo "<input type='text' name='".self::OPTION_KEY."[loggia_page_id]' value='{$val}' class='regular-text'>";
}

public function field_loggia_locale() {
  $opts = get_option(self::OPTION_KEY, []);
  $val = esc_attr($opts['loggia_locale'] ?? 'en');
  echo "<input type='text' name='".self::OPTION_KEY."[loggia_locale]' value='{$val}' class='small-text' placeholder='en'>";
}

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $nonce    = wp_create_nonce('hcn_import_nonce');
    $ajaxUrl  = admin_url('admin-ajax.php');
    $editBase = admin_url('post.php?action=edit&post=');

    ?>
    <div class="wrap">
      <h1>HavenConnect</h1>

      <hr>

      <form method="post" action="options.php" style="max-width:900px;">
        <?php
          settings_fields(self::OPTION_KEY);
          do_settings_sections('havenconnect');
          submit_button('Save Settings');
        ?>
      </form>

      <hr>

      <h2>Run Import (AJAX)</h2>
      <p>Queue defaults to <strong>ALL</strong> properties. You can run the first N items from the queue, or test import a single property UID.</p>

      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label>Run first N properties</label>
        <input id="hcn-run-first-n" type="number" min="1" value="20" style="width:90px;">
        <button class="button button-primary" id="hcn-run-first-btn">Run First N</button>
        <button class="button" id="hcn-run-all-btn">Run All</button>
      </div>

      <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label>Test single property UID</label>
        <input id="hcn-single-uid" type="text" placeholder="property uid…" style="width:420px;">
        <button class="button" id="hcn-run-single-btn">Run Single</button>
      </div>

      <h2 style="margin-top:25px;">Imported Items</h2>
      <div id="hcn-imported-list" style="border:1px solid #ddd;padding:12px;border-radius:8px;background:#fff;">
        <em>Nothing imported yet in this session.</em>
      </div>

      <h2 style="margin-top:25px;">Live Log</h2>
      <p>This updates automatically while an import is running.</p>

      <textarea id="hcn-log" readonly style="width:100%;min-height:240px;font-family:ui-monospace,Menlo,Consolas,monospace;"></textarea>

      <script>
        window.HCN_IMPORT = {
          ajaxUrl: <?php echo wp_json_encode($ajaxUrl); ?>,
          nonce: <?php echo wp_json_encode($nonce); ?>,
          editBase: <?php echo wp_json_encode($editBase); ?>
        };
      </script>
    </div>
    <?php
  }
}