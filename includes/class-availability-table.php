<?php
if (!defined('ABSPATH')) exit;

/**
 * Creates wp_hcn_availability table for lightning-fast availability & pricing search.
 */

class HavenConnect_Availability_Table {

    public static function install_table() {
        global $wpdb;

        $table = $wpdb->prefix . "hcn_availability";
        $charset = $wpdb->get_charset_collate();

        $sql = "
        CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_uid VARCHAR(64) NOT NULL,
            date DATE NOT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NULL,
            min_stay INT NULL,
            max_stay INT NULL,
            closed_to_arrival TINYINT(1) DEFAULT 0,
            closed_to_departure TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            INDEX idx_property_date (property_uid, date),
            INDEX idx_date (date)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}