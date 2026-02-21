<?php
if (!defined('ABSPATH')) exit;

add_action('wp_trash_post', function ($post_id) {
  if (get_post_type($post_id) !== 'hcn_property') return;

  // Only run if availability table singleton exists
  $table = $GLOBALS['havenconnect']['availability_table'] ?? null;
  if ($table && method_exists($table, 'delete_rows_for_post')) {
    $table->delete_rows_for_post((int)$post_id);
  }
}, 20);

add_action('before_delete_post', function ($post_id) {
  if (get_post_type($post_id) !== 'hcn_property') return;

  $table = $GLOBALS['havenconnect']['availability_table'] ?? null;
  if ($table && method_exists($table, 'delete_rows_for_post')) {
    $table->delete_rows_for_post((int)$post_id);
  }
}, 20);