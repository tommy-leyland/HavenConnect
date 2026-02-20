<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Taxonomy_Handler
 *
 * Registers and assigns:
 *   [l] → property_loc (Location)
 *   [g] → property_group (Group)
 */
class HavenConnect_Taxonomy_Handler {

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger;

        // MUST run early so admin menus/meta boxes appear correctly
        add_action('init', [$this, 'register_taxonomies'], 0);
    }

    /**
     * Registers both taxonomies for CPT hcn_property.
     */
    public function register_taxonomies() {

        $post_type = 'hcn_property';

        // LOCATION TAXONOMY
        register_taxonomy(
            'property_loc',
            [$post_type],
            [
                'labels' => [
                    'name'          => __('Property Locations', 'havenconnect'),
                    'singular_name' => __('Property Location', 'havenconnect'),
                    'menu_name'     => __('Locations', 'havenconnect'),
                ],
                'public'            => true,
                'hierarchical'      => false,     // keep as you had it (non-hierarchical)
                'show_ui'           => true,      // REQUIRED for admin UI
                'show_admin_column' => true,      // show column in table
                'show_in_rest'      => true,      // REQUIRED for Gutenberg
                'rewrite'           => ['slug' => 'property-location'],
            ]
        );

        // GROUP TAXONOMY
        register_taxonomy(
            'property_group',
            [$post_type],
            [
                'labels' => [
                    'name'          => __('Property Groups', 'havenconnect'),
                    'singular_name' => __('Property Group', 'havenconnect'),
                    'menu_name'     => __('Groups', 'havenconnect'),
                ],
                'public'            => true,
                'hierarchical'      => false,     // non-hierarchical = tag-like
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => ['slug' => 'property-group'],
            ]
        );

        if ($this->logger) {
            $this->logger->log("Taxonomies registered: property_loc, property_group.");
        }
    }

    /**
     * Assign terms generated from Hostfully tags.
     */
    public function apply_taxonomies($post_id, array $tags) {

        if (empty($tags)) {
            if ($this->logger) {
                $this->logger->log("No tags to process for post $post_id.");
            }
            return;
        }

        $loc_terms = [];
        $grp_terms = [];

        foreach ($tags as $tag) {

            $t = trim($tag);
            $t = preg_replace('/^\[(l|g)\]\s+/i', '[$1]', $t);

            if (stripos($t, '[l]') === 0) {
                $term = trim(substr($t, 3));
                if ($term !== '') $loc_terms[] = $term;
            }

            if (stripos($t, '[g]') === 0) {
                $term = trim(substr($t, 3));
                if ($term !== '') $grp_terms[] = $term;
            }
        }

        $loc_terms = array_unique($loc_terms);
        $grp_terms = array_unique($grp_terms);

        if ($loc_terms) {
            wp_set_object_terms($post_id, $loc_terms, 'property_loc', false);
            if ($this->logger) {
                $this->logger->log("Assigned property_loc → " . implode(', ', $loc_terms));
            }
        }

        if ($grp_terms) {
            wp_set_object_terms($post_id, $grp_terms, 'property_group', false);
            if ($this->logger) {
                $this->logger->log("Assigned property_group → " . implode(', ', $grp_terms));
            }
        }
    }
}