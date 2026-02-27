<?php
if (!defined('ABSPATH')) exit;

add_action('wp_trash_post', function ($post_id) {
  if (get_post_type($post_id) !== 'hcn_property') return;

  global $wpdb;
  $table = $wpdb->prefix . 'hcn_availability';
  $wpdb->delete($table, ['post_id' => (int)$post_id], ['%d']);
}, 20);

add_action('before_delete_post', function ($post_id) {
  if (get_post_type($post_id) !== 'hcn_property') return;

  global $wpdb;
  $table = $wpdb->prefix . 'hcn_availability';
  $wpdb->delete($table, ['post_id' => (int)$post_id], ['%d']);
}, 20);

add_action('untrash_post', function ($post_id) {
  if (get_post_type($post_id) !== 'hcn_property') return;

  // Only admins restoring posts
  if (!current_user_can('manage_options')) return;

  $hf_opts = get_option('hcn_hostfully', []);
  if (!is_array($hf_opts) || empty($hf_opts['api_key'])) {
    $hf_opts = get_option('havenconnect_options', []); // legacy fallback
  }
  $apiKey = trim((is_array($hf_opts) ? ($hf_opts['api_key'] ?? '') : ''));
  if (!$apiKey) return;

  $uid = get_post_meta($post_id, '_havenconnect_uid', true);
  if (!$uid) return;

  // Re-sync availability for the next 30 days (matches your testing clamp)
  if (isset($GLOBALS['havenconnect']['availability'])) {
    try {
      $from = gmdate('Y-m-d');
      $to   = gmdate('Y-m-d', strtotime('+30 days'));
      $GLOBALS['havenconnect']['availability']->sync_property_calendar($apiKey, $uid, (int)$post_id, $from, $to);
    } catch (\Throwable $e) {
      // Optional: log if you want
      if (!empty($GLOBALS['havenconnect']['logger'])) {
        $GLOBALS['havenconnect']['logger']->log("Availability restore sync failed for post {$post_id}: " . $e->getMessage());
        $GLOBALS['havenconnect']['logger']->save();
      }
    }
  }
}, 20);