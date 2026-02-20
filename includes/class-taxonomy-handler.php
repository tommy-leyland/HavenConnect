<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Taxonomy_Handler
 *
 * Maps Hostfully tag prefixes:
 *   [l] → property_loc (Location)
 *   [g] → property_group (Group)
 *
 * Ensures terms exist, assigns them to the property post.
 */
class HavenConnect_Taxonomy_Handler {

    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;

        // Ensure taxonomies exist
        add_action('init', [$this, 'register_taxonomies']);
    }

    /**
     * Registers taxonomies if they do not already exist.
     */
    public function register_taxonomies() {

        // property_loc (Location)
        if (!taxonomy_exists('property_loc')) {
            register_taxonomy(
                'property_loc',
                'hcn_property',
                [
                    'label'        => 'Property Locations',
                    'public'       => true,
                    'hierarchical' => false,
                    'show_ui'      => true,
                    'show_in_rest' => true,
                    'rewrite'      => [ 'slug' => 'property-location' ],
                ]
            );
        }

        // property_group
        if (!taxonomy_exists('property_group')) {
            register_taxonomy(
                'property_group',
                'hcn_property',
                [
                    'label'        => 'Property Groups',
                    'public'       => true,
                    'hierarchical' => false,
                    'show_ui'      => true,
                    'show_in_rest' => true,
                    'rewrite'      => [ 'slug' => 'property-group' ],
                ]
            );
        }
    }

    /**
     * Processes raw tags from Hostfully and assigns taxonomy terms.
     * @return void
     */
    public function apply_taxonomies($post_id, array $tags) {

        if (empty($tags)) {
            $this->logger->log("No tags to process for post $post_id.");
            return;
        }

        $loc_terms = [];
        $grp_terms = [];

        foreach ($tags as $tag) {
            $t = trim($tag);

            // Normalize "[l] Something" => "[l]Something"
            $t = preg_replace('/^\[(l|g)\]\s+/i', '[$1]', $t);

            // Location
            if (stripos($t, '[l]') === 0) {
                $term = trim(substr($t, 3));
                if ($term !== '') $loc_terms[] = $term;
            }

            // Group
            if (stripos($t, '[g]') === 0) {
                $term = trim(substr($t, 3));
                if ($term !== '') $grp_terms[] = $term;
            }
        }

        // Remove duplicates
        $loc_terms = array_unique($loc_terms);
        $grp_terms = array_unique($grp_terms);

        // Assign taxonomy terms
        if ($loc_terms) {
            wp_set_object_terms($post_id, $loc_terms, 'property_loc', false);
            $this->logger->log("Assigned property_loc → " . implode(', ', $loc_terms));
        }

        if ($grp_terms) {
            wp_set_object_terms($post_id, $grp_terms, 'property_group', false);
            $this->logger->log("Assigned property_group → " . implode(', ', $grp_terms));
        }
    }
}