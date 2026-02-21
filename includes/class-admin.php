<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Admin {

  const OPTION_KEY = 'havenconnect_settings';

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
        'api_key'    => sanitize_text_field($v['api_key'] ?? ''),
        'agency_uid' => sanitize_text_field($v['agency_uid'] ?? ''),
      ];
    });

    add_settings_section(
      'hcn_section_main',
      'HavenConnect Settings',
      function () {
        echo '<p>Enter your Hostfully credentials.</p>';
      },
      'havenconnect'
    );

    add_settings_field('hcn_api_key', 'API Key', [$this, 'field_api_key'], 'havenconnect', 'hcn_section_main');
    add_settings_field('hcn_agency_uid', 'Agency UID', [$this, 'field_agency_uid'], 'havenconnect', 'hcn_section_main');
  }

  public function field_api_key() {
    $opts = get_option(self::OPTION_KEY, []);
    $val  = esc_attr($opts['api_key'] ?? '');
    echo "<input type='text' name='" . self::OPTION_KEY . "[api_key]' value='{$val}' class='regular-text' autocomplete='off' />";
  }

  public function field_agency_uid() {
    $opts = get_option(self::OPTION_KEY, []);
    $val  = esc_attr($opts['agency_uid'] ?? '');
    echo "<input type='text' name='" . self::OPTION_KEY . "[agency_uid]' value='{$val}' class='regular-text' autocomplete='off' />";
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $nonce   = wp_create_nonce('hcn_import_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');
    $editBase = admin_url('post.php?action=edit&post=');

    ?>
    <div class="wrap">
      <h1>HavenConnect</h1>

      <form method="post" action="options.php">
        <?php
          settings_fields(self::OPTION_KEY);
          do_settings_sections('havenconnect');
          submit_button('Save Settings');
        ?>
      </form>

      <hr />

      <h2>Run Import (AJAX)</h2>
      <p style="max-width: 900px;">
        Queue defaults to <b>ALL properties</b>. You can run the first N items from the queue,
        or test import a single property UID.
      </p>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Run first N properties</th>
          <td>
            <input type="number" id="hcn_run_limit" value="10" min="1" step="1" style="width:90px" />
            <button class="button button-primary" id="hcn_run_first_n">Run First N</button>
            <button class="button" id="hcn_run_all">Run All</button>
          </td>
        </tr>
        <tr>
          <th scope="row">Test single property UID</th>
          <td>
            <input type="text" id="hcn_single_uid" placeholder="e.g. f65acb5e-bf68-4a41-a762-76fc9a8162f9" style="width:520px" />
            <button class="button" id="hcn_run_single">Run Single</button>
          </td>
        </tr>
      </table>

      <div id="hcn_progress" style="margin-top:12px;"></div>

      <h2>Imported Items</h2>
      <div id="hcn_imported" style="margin:8px 0 16px 0;">
        <em>Nothing imported yet in this session.</em>
      </div>

      <h2>Live Log</h2>
      <p style="margin-top:0;">This updates automatically while an import is running.</p>
      <pre id="hcn_log_live" style="background:#111;color:#ddd;padding:12px;max-height:420px;overflow:auto;white-space:pre-wrap;"></pre>
    </div>

    <script>
    (function(){
      const ajaxUrl  = <?php echo json_encode($ajaxUrl); ?>;
      const nonce    = <?php echo json_encode($nonce); ?>;
      const editBase = <?php echo json_encode($editBase); ?>;

      let logTimer = null;
      let running  = false;
      let logOffset = 0;

      function setProgress(html){
        document.getElementById('hcn_progress').innerHTML = html;
      }

      function addImportedLine(data){
        const box = document.getElementById('hcn_imported');
        if (!box) return;

        if (box.querySelector('em')) box.innerHTML = '';

        const uid = (data && data.uid) ? data.uid : '';
        const name = (data && data.name) ? data.name : 'Imported';
        const postId = (data && data.post_id) ? data.post_id : '';
        const editUrl = postId ? (editBase + postId) : '';

        const div = document.createElement('div');
        div.style.marginBottom = '6px';

        const safeName = document.createElement('span');
        safeName.textContent = name + (uid ? (' (' + uid + ')') : '');

        div.appendChild(safeName);

        if (editUrl) {
          const a = document.createElement('a');
          a.href = editUrl;
          a.target = '_blank';
          a.style.marginLeft = '10px';
          a.textContent = 'Edit Post #' + postId;
          div.appendChild(a);
        }

        box.appendChild(div);
      }

      async function post(action, data){
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
        const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        return res.json();
      }

      async function fetchLog(){
        const res = await post('hcn_get_log', { offset: logOffset });
        if (res && res.success && res.data) {
            const chunk = res.data.chunk || '';
            logOffset = typeof res.data.offset === 'number' ? res.data.offset : logOffset;

            if (chunk) {
            const el = document.getElementById('hcn_log_live');
            if (!el) return;

            const atBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 20);
            el.textContent += chunk;
            if (atBottom) el.scrollTop = el.scrollHeight;
            }
        }
        }

      function startLogPolling(){
        stopLogPolling();
        fetchLog();
        logTimer = setInterval(fetchLog, 750);
      }

      function stopLogPolling(){
        if (logTimer) clearInterval(logTimer);
        logTimer = null;
      }

      function disableButtons(disabled){
        ['hcn_run_first_n','hcn_run_all','hcn_run_single'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.disabled = !!disabled;
        });
      }

      async function runQueue(runLimit, mode, singleUid){
        if (running) return;
        running = true;
        disableButtons(true);
        startLogPolling();

        try {
          // Build queue
          setProgress('Building queue…');

          const startPayload = { mode: mode || 'all' };
          if (singleUid) startPayload.property_uid = singleUid;

          document.getElementById('hcn_log_live').textContent = '';
          logOffset = 0;

          const start = await post('hcn_import_start', startPayload);
          if (!start.success) {
            setProgress('<span style="color:#b00;">Start failed: ' + (start.data?.message || 'Unknown') + '</span>');
            return;
          }

          const jobId = start.data.job_id;
          const total = start.data.total;
          const limit = (runLimit === null) ? total : Math.min(runLimit, total);

          // reset imported list each run
          document.getElementById('hcn_imported').innerHTML = '<em>Running…</em>';

          setProgress(
            'Queue created (<b>' + (start.data.source || 'all') + '</b>): ' +
            total + ' item(s). Running <b>' + limit + '</b>…'
          );

          for (let i = 0; i < limit; i++){
            setProgress('Importing ' + (i+1) + ' / ' + limit + ' …');

            const single = await post('hcn_import_single', { job_id: jobId, index: i });

            if (!single.success) {
              setProgress('<span style="color:#b00;">Error on index ' + i + ': ' + (single.data?.message || 'Unknown') + '</span>');
              return;
            }

            // Show imported item + edit link
            addImportedLine(single.data);

            if (single.data?.post_id) {
              setProgress(
                'Imported ' + (i+1) + '/' + limit +
                ' — <a href="' + (editBase + single.data.post_id) + '" target="_blank">Edit Post #' + single.data.post_id + '</a>'
              );
            }
          }

          await post('hcn_import_finish', { job_id: jobId });
          setProgress('<b>Done.</b> Imported ' + limit + ' item(s).');

        } catch (e) {
          setProgress('<span style="color:#b00;">Unexpected error: ' + (e && e.message ? e.message : e) + '</span>');
        } finally {
          stopLogPolling();
          await fetchLog();
          disableButtons(false);
          running = false;
        }
      }

      // Buttons
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
        // Build a single-item queue and run index 0
        runQueue(1, 'all', uid);
      });

      // Initial log fetch (so the box isn't empty)
      fetchLog();

    })();
    </script>
    <?php
  }
}