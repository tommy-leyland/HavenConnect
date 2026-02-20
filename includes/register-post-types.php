<?php
if (!defined('ABSPATH')) exit;

/**
 * Register HavenConnect post type + taxonomies
 * Slugs are namespaced to avoid conflicts.
 */

add_action('init', function() {

    // ---------- CUSTOM POST TYPE ----------
    $slug = 'hcn_property';

    // Only register if no other plugin has claimed it
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

    // ---------- TAXONOMIES ----------
    // LOCATION taxonomy
    if (!taxonomy_exists('hcn_property_location')) {
        register_taxonomy(
            'hcn_property_location',
            $slug,
            [
                'label'        => 'Locations',
                'hierarchical' => true,
                'show_in_rest' => true,
            ]
        );
    }

    // GROUP taxonomy (tags-style)
    if (!taxonomy_exists('hcn_property_group')) {
        register_taxonomy(
            'hcn_property_group',
            $slug,
            [
                'label'        => 'Groups',
                'hierarchical' => false,
                'show_in_rest' => true,
            ]
        );
    }

    // FEATURES taxonomy (optional)
    if (!taxonomy_exists('hcn_property_feature')) {
        register_taxonomy(
            'hcn_property_feature',
            $slug,
            [
                'label'        => 'Features',
                'hierarchical' => false,
                'show_in_rest' => true,
            ]
        );
    }

});