<?php
if (!defined('ABSPATH')) exit;

/**
 * Register HavenConnect post type ONLY.
 * (Taxonomies now live exclusively in class-taxonomy-handler.php)
 */

add_action('init', function() {

    // ---------- CUSTOM POST TYPE ----------
    $slug = 'hcn_property';

    if (!post_type_exists($slug)) {

        register_post_type($slug, [
            'labels' => [
                'name'          => 'Properties',
                'singular_name' => 'Property',
            ],
            'public'            => true,
            'has_archive'       => true,
            'rewrite'           => ['slug' => 'properties'],
            'supports'          => ['title', 'editor', 'thumbnail'],
            'show_in_rest'      => true,
            'menu_icon'         => 'dashicons-admin-home',
        ]);
    }

    // IMPORTANT:
    // No taxonomy registration here anymore.
    // Taxonomies are now registered inside class-taxonomy-handler.php 
    // at init priority 0 so menus and meta boxes work correctly.
});