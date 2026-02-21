<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {

  register_post_type('hcn_property', [
    'labels' => [
      'name'          => 'Properties',
      'singular_name' => 'Property',
    ],
    'public'       => true,
    'has_archive'  => true,
    'rewrite'      => ['slug' => 'properties'],
    'show_in_rest' => true,
    'menu_icon'    => 'dashicons-admin-home',

    // ✅ Add 'excerpt' so WP shows/stores it properly
    'supports'     => ['title', 'editor', 'excerpt', 'thumbnail'],
  ]);

  // IMPORTANT:
  // No taxonomy registration here anymore.
  // Taxonomies are registered inside class-taxonomy-handler.php.
});