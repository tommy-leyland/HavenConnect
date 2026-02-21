<?php
if (!defined('ABSPATH')) exit;

add_action('hcn_daily_availability_cleanup', function () {
  $table = $GLOBALS['havenconnect']['availability_table'] ?? null;
  if ($table && method_exists($table, 'purge_orphan_rows')) {
    $table->purge_orphan_rows();
  }
});

// Schedule once daily if not already scheduled
add_action('init', function () {
  if (!wp_next_scheduled('hcn_daily_availability_cleanup')) {
    wp_schedule_event(time() + 300, 'daily', 'hcn_daily_availability_cleanup');
  }
});