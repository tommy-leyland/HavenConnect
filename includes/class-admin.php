<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Admin
 *
 * Provides:
 *  - Settings page
 *  - Save API key + Agency UID
 *  - Button to trigger Featured Import
 *  - Log viewer
 */
class HavenConnect_Admin {

    private $importer;
    private $logger;

    const OPTION = 'havenconnect_settings';
    const ACTION = 'havenconnect_run_import';

    public function __construct($importer, $logger) {
        $this->importer = $importer;
        $this->logger   = $logger;

        add_action('admin_menu',        [$this, 'menu']);
        add_action('admin_init',        [$this, 'settings_init']);
        add_action('admin_post_'.self::ACTION, [$this, 'handle_import']);
        add_action('admin_init', function() {
            if (isset($_POST['hcn_action']) && $_POST['hcn_action'] === 'import_features') {

                check_admin_referer('hcn_import_features');

                $opts = get_option(HavenConnect_Admin::OPTION, []);
                $api  = $opts['api_key'] ?? '';

                $importer = $GLOBALS['havenconnect']['importer'] ?? null;
                if ($importer) {
                    $importer->import_default_amenities($api);
                }

                wp_redirect(admin_url('options-general.php?page=havenconnect'));
                exit;
            }
        });
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
                'api_key'   => sanitize_text_field($v['api_key']   ?? ''),
                'agency_uid'=> sanitize_text_field($v['agency_uid'] ?? ''),
            ];
        });

        add_settings_section(
            'hcn_section_main',
            'HavenConnect Settings',
            function() { echo '<p>Enter your PMS credentials.</p>'; },
            'havenconnect'
        );

        add_settings_field(
            'hcn_api_key',
            'API Key',
            [$this, 'field_api_key'],
            'havenconnect',
            'hcn_section_main'
        );

        add_settings_field(
            'hcn_agency_uid',
            'Agency UID',
            [$this, 'field_agency_uid'],
            'havenconnect',
            'hcn_section_main'
        );
    }

    /** Render API key field */
    public function field_api_key() {
        $opts = get_option(self::OPTION, []);
        ?>
        <input type="password"
               class="regular-text"
               name="<?php echo self::OPTION; ?>[api_key]"
               value="<?php echo esc_attr($opts['api_key'] ?? ''); ?>">
        <?php
    }

    /** Render Agency UID field */
    public function field_agency_uid() {
        $opts = get_option(self::OPTION, []);
        ?>
        <input type="text"
               class="regular-text"
               name="<?php echo self::OPTION; ?>[agency_uid]"
               value="<?php echo esc_attr($opts['agency_uid'] ?? ''); ?>">
        <?php
    }

    /**
     * Main settings page markup
     */
    public function render_settings_page() {
        $opts  = get_option(self::OPTION, []);
        $nonce = wp_create_nonce('hcn_import_nonce');
        ?>
        <div class="wrap">
            <h1>HavenConnect</h1>

            <!-- SETTINGS FORM -->
            <form method="post" action="options.php">
                <?php
                    settings_fields(self::OPTION);
                    do_settings_sections('havenconnect');
                    submit_button('Save Settings');
                ?>
            </form>

            <hr>

            <!-- AJAX IMPORT SECTION -->
            <h2>Run Import (AJAX)</h2>
            <p>
                Imports all Featured properties via background AJAX requests.
                Zero timeouts, live progress below.
            </p>

            <div id="hcn-import-ui" style="padding:12px;border:1px solid #ddd;background:#fafafa;margin-bottom:20px;">
                <button id="hcn-import-start" class="button button-primary">
                    Run AJAX Import
                </button>
                <span id="hcn-import-status" style="margin-left:10px;font-weight:bold;"></span>

                <div id="hcn-import-log"
                    style="margin-top:12px;max-height:300px;overflow:auto;background:#fff;border:1px solid #ccc;padding:10px;font-size:12px;">
                </div>
            </div>

            <!-- NONCE FOR AJAX -->
            <script>
            const HCN_IMPORT_NONCE = "<?php echo esc_js($nonce); ?>";
            const HCN_AJAX_URL     = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
            </script>

            <!-- AJAX IMPORT SCRIPT -->
            <script>
            (function(){

            const ajaxUrl = HCN_AJAX_URL;
            const nonce   = HCN_IMPORT_NONCE;

            const startBtn = document.getElementById('hcn-import-start');
            const statusEl = document.getElementById('hcn-import-status');
            const logEl    = document.getElementById('hcn-import-log');

            // --- PROGRESS BAR ---
            let barContainer = document.createElement('div');
            barContainer.style.width = "100%";
            barContainer.style.height = "18px";
            barContainer.style.background = "#e2e2e2";
            barContainer.style.marginTop = "10px";
            barContainer.style.borderRadius = "4px";

            let barFill = document.createElement('div');
            barFill.style.height = "100%";
            barFill.style.width = "0%";
            barFill.style.background = "#007cba";
            barFill.style.borderRadius = "4px";
            barFill.style.transition = "width 0.25s ease";

            barContainer.appendChild(barFill);
            statusEl.parentNode.insertBefore(barContainer, statusEl.nextSibling);

            function setProgress(percent) {
                barFill.style.width = percent + "%";
            }

            // --- LOG HELPER ---
            function log(msg, isError=false) {
                const p = document.createElement('div');
                p.textContent = msg;
                if (isError) p.style.color = '#a00';
                logEl.appendChild(p);
                logEl.scrollTop = logEl.scrollHeight;
            }

            function setStatus(t) {
                statusEl.textContent = t;
            }

            async function post(action, data) {
                const form = new FormData();
                form.append('action', action);
                form.append('nonce',  nonce);

                for (const k in data) form.append(k, data[k]);

                const res  = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: form
                });

                const json = await res.json();

                if (!json.success) {
                    throw new Error(json.data && json.data.message ? json.data.message : "Unknown error");
                }

                return json.data;
            }

            async function importQueue(job_id, items) {
                const total = items.length;

                for (let i = 0; i < total; i++) {
                    const it = items[i];

                    const pct = Math.round(((i) / total) * 100);
                    setProgress(pct);

                    setStatus(`Importing ${i+1} of ${total}: ${it.name}`);

                    try {
                        await post('hcn_import_single', {
                            job_id: job_id,
                            index:  i
                        });
                        log(`✔ Imported: ${it.name}`);
                    } catch (e) {
                        log(`✖ Failed: ${it.name} – ${e.message}`, true);
                    }
                }

                // 100%
                setProgress(100);
            }

            async function finish(job_id) {
                try { await post('hcn_import_finish', { job_id }); }
                catch(e){}

                setStatus('Done.');
            }

            // --- BUTTON CLICK ---
            startBtn.addEventListener('click', async function() {
                startBtn.disabled = true;
                logEl.innerHTML = "";
                setProgress(0);
                setStatus('Preparing queue...');

                try {
                    const start = await post('hcn_import_start', {});
                    log(`Queue created for ${start.total} properties.`);

                    await importQueue(start.job_id, start.items);
                    await finish(start.job_id);
                }
                catch(e){
                    log("Start failed: " + e.message, true);
                    setStatus('Failed.');
                }
                finally {
                    startBtn.disabled = false;
                }
            });

        })();
            </script>

            <hr>

            <!-- OLD LOG VIEWER -->
            <?php $this->render_log(); ?>
        </div>
        <?php
    }

    /**
     * Handle Import Submission
     */
    public function handle_import() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer(self::ACTION);

        $opts = get_option(self::OPTION, []);
        $api  = $opts['api_key']    ?? '';
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

    /**
     * Log viewer
     */
    private function render_log() {
        $log = $this->logger->get();
        if (!$log) return;

        echo '<h2>Last Log</h2>';
        echo '<pre style="background:#111;color:#eee;padding:12px;max-height:350px;overflow:auto;border-radius:6px;">';
        echo esc_html($log);
        echo '</pre>';
    }
}

