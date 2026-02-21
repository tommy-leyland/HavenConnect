<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Admin {

  const OPTION = 'havenconnect_settings';
  const ACTION = 'havenconnect_import';

  private $importer;
  private $logger;

  public function __construct($importer, $logger) {
    $this->importer = $importer;
    $this->logger   = $logger;

    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'settings_init']);

    // keep legacy non-AJAX import handler if you still use it anywhere
    add_action('admin_post_' . self::ACTION, [$this, 'handle_import']);
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
    register_setting(self::OPTION, self::OPTION, function($v){
      return [
        'api_key'     => sanitize_text_field($v['api_key'] ?? ''),
        'agency_uid'  => sanitize_text_field($v['agency_uid'] ?? ''),
      ];
    });

    add_settings_section(
      'hcn_section_main',
      'HavenConnect Settings',
      function(){ echo '<p>Enter your Hostfully credentials.</p>'; },
      'havenconnect'
    );

    add_settings_field('hcn_api_key', 'API Key', [$this, 'field_api_key'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_agency_uid', 'Agency UID', [$this, 'field_agency_uid'], 'havenconnect', 'hcn_section_main');
  }

  public function field_api_key() {
    $opts = get_option(self::OPTION, []);
    $val  = esc_attr($opts['api_key'] ?? '');
    echo "<input type='text' name='" . self::OPTION . "[api_key]' value='{$val}' class='regular-text' />";
  }

  public function field_agency_uid() {
    $opts = get_option(self::OPTION, []);
    $val  = esc_attr($opts['agency_uid'] ?? '');
    echo "<input type='text' name='" . self::OPTION . "[agency_uid]' value='{$val}' class='regular-text' />";
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $nonce = wp_create_nonce('hcn_import_nonce');
    $ajax  = admin_url('admin-ajax.php');
    ?>
    <div class="wrap">
      <h1>HavenConnect</h1>

      <form method="post" action="options.php">
        <?php
          settings_fields(self::OPTION);
          do_settings_sections('havenconnect');
          submit_button('Save Settings');
        ?>
      </form>

      <hr />

      <h2>Run Import (AJAX)</h2>
      <p>
        Queue always builds from <b>ALL properties</b> by default (no “featured” reliance).
        Choose how many to run, or test a single property UID.
      </p>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Run first N properties</th>
          <td>
            <input type="number" id="hcn_run_limit" value="10" min="1" style="width:90px" />
            <button class="button button-primary" id="hcn_run_first_n">Run First N</button>
            <button class="button" id="hcn_run_all">Run All</button>
          </td>
        </tr>
        <tr>
          <th scope="row">Test single property UID</th>
          <td>
            <input type="text" id="hcn_single_uid" placeholder="e.g. f65acb5e-bf68-..." style="width:420px" />
            <button class="button" id="hcn_run_single">Run Single</button>
          </td>
        </tr>
      </table>

      <div id="hcn_progress" style="margin-top:12px;"></div>

      <h2>Log</h2>
      <pre style="background:#111;color:#ddd;padding:12px;max-height:380px;overflow:auto;"><?php
        echo esc_html($this->logger->get() ?: '');
      ?></pre>
    </div>

    <script>
    (function(){
      const ajaxUrl = <?php echo json_encode($ajax); ?>;
      const nonce   = <?php echo json_encode($nonce); ?>;

      async function post(action, data){
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        Object.keys(data||{}).forEach(k => fd.append(k, data[k]));
        const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        return res.json();
      }

      function setProgress(html){
        document.getElementById('hcn_progress').innerHTML = html;
      }

      async function runQueue(runLimit, mode, singleUid){
        setProgress('Building queue…');

        const startPayload = { mode: mode || 'all' };
        if (singleUid) startPayload.property_uid = singleUid;

        const start = await post('hcn_import_start', startPayload);
        if (!start.success) {
          setProgress('<span style="color:#b00;">Start failed: ' + (start.data?.message || 'Unknown') + '</span>');
          return;
        }

        const jobId = start.data.job_id;
        const total = start.data.total;
        const limit = (runLimit === null) ? total : Math.min(runLimit, total);

        setProgress('Queue created (' + start.data.source + '): ' + total + ' items. Running ' + limit + '…');

        for (let i=0; i<limit; i++){
          setProgress('Importing ' + (i+1) + ' / ' + limit + ' …');
          const single = await post('hcn_import_single', { job_id: jobId, index: i });
          if (!single.success){
            setProgress('<span style="color:#b00;">Error on index ' + i + ': ' + (single.data?.message || 'Unknown') + '</span>');
            return;
          }
        }

        await post('hcn_import_finish', { job_id: jobId });
        setProgress('<b>Done.</b> Imported ' + limit + ' item(s). Refresh page to see latest log.');
      }

      document.getElementById('hcn_run_first_n').addEventListener('click', function(e){
        e.preventDefault();
        const n = parseInt(document.getElementById('hcn_run_limit').value || '10', 10);
        runQueue(n, 'all', '');
      });

      document.getElementById('hcn_run_all').addEventListener('click', function(e){
        e.preventDefault();
        runQueue(null, 'all', '');
      });

      document.getElementById('hcn_run_single').addEventListener('click', function(e){
        e.preventDefault();
        const uid = (document.getElementById('hcn_single_uid').value || '').trim();
        if (!uid) {
          setProgress('<span style="color:#b00;">Enter a property UID first.</span>');
          return;
        }
        // Build a single-item queue and import index 0
        runQueue(1, 'all', uid);
      });

    })();
    </script>
    <?php
  }

  // Legacy handler (kept)
  public function handle_import() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $opts = get_option(self::OPTION, []);
    $api  = $opts['api_key'] ?? '';
    $uid  = $opts['agency_uid'] ?? '';
    if (!$api || !$uid) {
      $this->logger->log('Missing API key or Agency UID.');
      $this->logger->save();
      wp_redirect(admin_url('options-general.php?page=havenconnect'));
      exit;
    }
    $this->importer->run_import($api, $uid);
    $this->logger->save();
    wp_redirect(admin_url('options-general.php?page=havenconnect'));
    exit;
  }
}