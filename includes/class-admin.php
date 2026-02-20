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

            <hr>
            <h2>Run Import</h2>
            <p>Imports up to <strong>10 Featured</strong> properties, including tags + photos.</p>

            <form method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::ACTION); ?>
                  <input type="hidden" name="action"
                         value="<?php echo esc_attr(self::ACTION); ?>">
                  <?php submit_button('Run Featured Import', 'primary'); ?>
            </form>

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