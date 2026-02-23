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
      'Provider Credentials',
      function () {
        echo '<p>Configure your provider API credentials and Google Maps key.</p>';
      },
      'havenconnect'
    );

    add_settings_field('hcn_api_key', 'Hostfully API Key', [$this, 'field_api_key'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_agency_uid', 'Hostfully Agency UID', [$this, 'field_agency_uid'], 'havenconnect', 'hcn_section_main');

    add_settings_field('hcn_loggia_base_url', 'Loggia Base URL', [$this, 'field_loggia_base_url'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_api_key',  'Loggia API Key',  [$this, 'field_loggia_api_key'],  'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_page_id',  'Loggia Page ID',  [$this, 'field_loggia_page_id'],  'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_loggia_locale',   'Loggia Locale',   [$this, 'field_loggia_locale'],   'havenconnect', 'hcn_section_main');

    add_settings_field('hcn_google_maps_key', 'Google Maps API Key', [$this, 'field_google_maps_api_key'], 'havenconnect', 'hcn_section_main');
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

  public function enqueue_admin_assets($hook) {
    if ($hook !== 'settings_page_havenconnect') return;

	wp_enqueue_script(
	'hcn-admin-import',
	plugin_dir_url(__FILE__) . '../assets/hcn-admin-import.js',
	[],
	'1.3.1',
	true
	);

    // Tiny admin styles for tabs/cards (kept inline to avoid another file)
    $css = "
      .hcn-tabs { margin: 16px 0; }
      .hcn-tabcontent { background:#fff; border:1px solid #ccd0d4; border-top:0; padding:16px; border-radius:0 0 8px 8px; }
      .hcn-card { border:1px solid #e5e7eb; border-radius:10px; padding:14px; background:#fff; }
      .hcn-grid { display:grid; grid-template-columns: 1fr; gap:12px; }
      @media (min-width: 1100px) { .hcn-grid { grid-template-columns: 1fr 1fr; } }
      .hcn-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
      .hcn-muted { opacity:.75; }
      .hcn-log { width:100%; min-height:260px; font-family: ui-monospace, Menlo, Consolas, monospace; }
    ";
    wp_add_inline_style('wp-admin', $css);
  }

  private function active_tab(): string {
    $tab = sanitize_text_field($_GET['tab'] ?? 'import');
    return in_array($tab, ['import', 'providers'], true) ? $tab : 'import';
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $tab     = $this->active_tab();
    $nonce   = wp_create_nonce('hcn_import_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');
    $editBase = admin_url('post.php?action=edit&post=');

    $opts = get_option(self::OPTION_KEY, []);
    $has_loggia = !empty($opts['loggia_base_url']) && !empty($opts['loggia_api_key']) && !empty($opts['loggia_page_id']);

    ?>
    <div class="wrap">
      <h1>HavenConnect</h1>

      <h2 class="nav-tab-wrapper hcn-tabs">
        <a class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>"
           href="<?php echo esc_url(admin_url('options-general.php?page=havenconnect&tab=import')); ?>">
          Import Settings
        </a>
        <a class="nav-tab <?php echo $tab === 'providers' ? 'nav-tab-active' : ''; ?>"
           href="<?php echo esc_url(admin_url('options-general.php?page=havenconnect&tab=providers')); ?>">
          Providers
        </a>
      </h2>

      <div class="hcn-tabcontent">
        <?php if ($tab === 'import') : ?>

          <p class="hcn-muted">Run imports via AJAX queue (safe for large batches). Use “First N” for quick checks.</p>

          <div class="hcn-grid">
            <div class="hcn-card hcn-import-box" data-provider="both" data-mode="all">
              <h2 style="margin-top:0;">Import All Properties (Hostfully + Loggia)</h2>
              <p class="hcn-muted">Builds a combined queue and runs through it. Single-ID testing is done below per provider.</p>

              <div class="hcn-row">
                <label><strong>Run first</strong></label>
                <input data-role="first-n" type="number" min="1" value="10" style="width:90px;">
                <button class="button button-primary" data-action="run-first">Run First N</button>
                <button class="button" data-action="run-all">Run All</button>
              </div>

              <p class="hcn-muted" style="margin-bottom:0;">
                Hostfully respects “mode” (all/featured). Loggia ignores mode.
              </p>

              <div class="hcn-row" style="margin-top:10px;">
                <label class="hcn-muted">Hostfully mode</label>
                <select data-role="mode">
                  <option value="all" selected>All</option>
                  <option value="featured">Featured</option>
                </select>
              </div>
            </div>

            <div class="hcn-card hcn-import-box" data-provider="hostfully" data-mode="all">
              <h2 style="margin-top:0;">Import Hostfully Properties</h2>

              <div class="hcn-row">
                <label><strong>Run first</strong></label>
                <input data-role="first-n" type="number" min="1" value="10" style="width:90px;">
                <button class="button button-primary" data-action="run-first">Run First N</button>
                <button class="button" data-action="run-all">Run All</button>
              </div>

              <div class="hcn-row" style="margin-top:10px;">
                <label class="hcn-muted">Mode</label>
                <select data-role="mode">
                  <option value="all" selected>All</option>
                  <option value="featured">Featured</option>
                </select>
              </div>

              <hr style="margin:14px 0;">

              <div class="hcn-row">
                <label><strong>Test single UID</strong></label>
                <input data-role="single-id" type="text" placeholder="Hostfully property UID…" style="width:420px; max-width:100%;">
                <button class="button" data-action="run-single">Run Single</button>
              </div>
            </div>

            <div class="hcn-card hcn-import-box" data-provider="loggia" data-mode="all">
              <h2 style="margin-top:0;">Import Loggia Properties</h2>
              <?php if (!$has_loggia) : ?>
                <p style="color:#b45309; margin-top:0;">
                  Loggia is not configured yet (missing Base URL / API Key / Page ID). Import buttons are disabled.
                </p>
              <?php else: ?>
                <p class="hcn-muted" style="margin-top:0;">Loggia import is multi-endpoint; start with small batches.</p>
              <?php endif; ?>

              <div class="hcn-row">
                <label><strong>Run first</strong></label>
                <input data-role="first-n" type="number" min="1" value="5" style="width:90px;" <?php echo $has_loggia ? '' : 'disabled'; ?>>
                <button class="button button-primary" data-action="run-first" <?php echo $has_loggia ? '' : 'disabled'; ?>>Run First N</button>
                <button class="button" data-action="run-all" <?php echo $has_loggia ? '' : 'disabled'; ?>>Run All</button>
              </div>

              <hr style="margin:14px 0;">

              <div class="hcn-row">
                <label><strong>Test single ID</strong></label>
                <input data-role="single-id" type="text" placeholder="Loggia property id…" style="width:420px; max-width:100%;" <?php echo $has_loggia ? '' : 'disabled'; ?>>
                <button class="button" data-action="run-single" <?php echo $has_loggia ? '' : 'disabled'; ?>>Run Single</button>
              </div>

            </div>
          </div>

          <h2 style="margin-top:18px;">Imported Items</h2>
          <div id="hcn-imported-list" class="hcn-card">
            <em>Nothing imported yet in this session.</em>
          </div>

          <h2 style="margin-top:18px;">Live Log</h2>
          <p class="hcn-muted">Updates automatically while an import is running.</p>
          <textarea id="hcn-log" class="hcn-log" readonly></textarea>

        <?php else : ?>

          <h2 style="margin-top:0;">Provider Configuration</h2>
          <p class="hcn-muted">These values are stored in the WordPress options table. No wp-config edits.</p>

          <form method="post" action="options.php" style="max-width:900px;">
            <?php
              settings_fields(self::OPTION_KEY);
              do_settings_sections('havenconnect');
              submit_button('Save Settings');
            ?>
          </form>

          <div class="hcn-card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Loggia Connection Test</h3>
            <p class="hcn-muted">Calls the Loggia list endpoint and reports whether it responds.</p>
            <button class="button" id="hcn-loggia-test-btn">Test Loggia Connection</button>
            <pre id="hcn-loggia-test-output" style="margin-top:10px; background:#111; color:#eee; padding:10px; border-radius:8px; max-height:220px; overflow:auto;"></pre>
            <button class="button" id="hcn-ping-btn">Ping AJAX</button>
            <pre id="hcn-ping-out" style="margin-top:8px;"></pre>
          </div>

        <?php endif; ?>

        <script>
            window.HCN_IMPORT = {
                ajaxUrl: <?php echo wp_json_encode($ajaxUrl); ?>,
                nonce: <?php echo wp_json_encode($nonce); ?>,
                editBase: <?php echo wp_json_encode($editBase); ?>
            };
        </script>

      </div>
    </div>
    <?php
  }
}