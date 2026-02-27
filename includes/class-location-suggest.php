<?php
if (!defined('ABSPATH')) { exit; }

class HavenConnect_Location_Suggest_Ajax {
  const ACTION = 'hcn_location_suggest';

  public function __construct() {
    add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
  }

  public function handle() {
    $q = sanitize_text_field($_GET['q'] ?? '');
    $tax = sanitize_key($_GET['tax'] ?? 'property_loc');

    if (strlen($q) < 1) {
      wp_send_json_success(['items' => []]); 
    }

    $terms = get_terms([
      'taxonomy'   => $tax,
      'hide_empty' => false,
      'number'     => 10,
      'search'     => $q,
    ]);

    if (is_wp_error($terms)) {
      wp_send_json_success(['items' => []]);
    }

    $items = [];
    foreach ($terms as $t) {
      $items[] = [
        'id'   => (int)$t->term_id,
        'name' => $t->name,
        'slug' => $t->slug,
      ];
    }

    wp_send_json_success(['items' => $items]);
  }
}