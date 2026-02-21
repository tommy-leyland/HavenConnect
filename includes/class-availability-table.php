<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Availability_Table {

  /**
   * Canonical availability table used for fast searches.
   * This method is idempotent: safe to run repeatedly.
   */
  public static function install_or_upgrade(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'hcn_availability';
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1) Create canonical table definition (dbDelta will add missing columns/indexes, but won't drop old columns)
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NOT NULL,
      property_uid VARCHAR(64) NOT NULL,
      for_date DATE NOT NULL,

      price DECIMAL(10,2) NULL,
      currency VARCHAR(8) NULL,

      unavailable TINYINT(1) NOT NULL DEFAULT 0,
      

      checkin TINYINT(1) NULL,
      checkout TINYINT(1) NULL,

      min_stay INT NULL,
      max_stay INT NULL,

      
      updated_at DATETIME NULL,

      PRIMARY KEY (id),
      UNIQUE KEY uniq_property_date (property_uid, for_date),
      KEY idx_post_date (post_id, for_date),
      KEY idx_date (for_date)
    ) {$charset};";

    dbDelta($sql);

    // 2) If table existed with legacy columns, migrate / cleanup safely.

    // Fetch current columns
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
    $names = is_array($cols) ? array_map(fn($c) => $c['Field'], $cols) : [];

    // If legacy 'date' exists and for_date is empty anywhere (shouldn't be, but safe), copy across
    if (in_array('date', $names, true) && in_array('for_date', $names, true)) {
      $wpdb->query("UPDATE {$table} SET for_date = `date` WHERE (for_date IS NULL OR for_date = '0000-00-00') AND `date` IS NOT NULL AND `date` <> '0000-00-00'");
    }

    // Ensure dedupe before adding unique key (defensive)
    // Keep the highest id for any duplicate (property_uid, for_date)
    $wpdb->query("
      DELETE t1 FROM {$table} t1
      INNER JOIN {$table} t2
        ON t1.property_uid = t2.property_uid
       AND t1.for_date = t2.for_date
       AND t1.id < t2.id
    ");

    // 3) Drop legacy columns (dbDelta does NOT drop)
    $drop = [];
    foreach (['date','is_available','closed_to_arrival','closed_to_departure','reason','notes'] as $legacy) {
      if (in_array($legacy, $names, true)) {
        $drop[] = "DROP COLUMN `{$legacy}`";
      }
    }
    if (!empty($drop)) {
      $wpdb->query("ALTER TABLE {$table} " . implode(', ', $drop));
    }

    // 4) Re-check required indexes exist (dbDelta should do it, but we’re explicit/defensive)
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    $indexNames = is_array($indexes) ? array_unique(array_map(fn($i) => $i['Key_name'], $indexes)) : [];

    if (!in_array('uniq_property_date', $indexNames, true)) {
      $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uniq_property_date (property_uid, for_date)");
    }
    if (!in_array('idx_post_date', $indexNames, true)) {
      $wpdb->query("ALTER TABLE {$table} ADD KEY idx_post_date (post_id, for_date)");
    }
    if (!in_array('idx_date', $indexNames, true)) {
      $wpdb->query("ALTER TABLE {$table} ADD KEY idx_date (for_date)");
    }
  }
}