<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Taxonomy_Handler (FINAL)
 *
 * Registers:
 *  - property_loc   (hierarchical)  → Location taxonomy
 *  - property_group (hierarchical)  → Group taxonomy
 *  - hcn_feature    (non-hierarchical) → Amenities/Features (populated by the amenities importer)
 *
 * Also provides:
 *  - apply_taxonomies( $post_id, array $tags ): assigns Location/Group from tag prefixes.
 *
 * Notes:
 *  - We intentionally register taxonomies on init **after** CPT registration (priority 12)
 *    so they attach reliably to the 'hcn_property' post type and show in the left menu.
 */
class HavenConnect_Taxonomy_Handler {

    /** @var HavenConnect_Logger|null */
    private $logger;

    /** @var string CPT slug to attach to */
    private $cpt = 'hcn_property';

    public function __construct($logger = null) {
        $this->logger = $logger;

        // Register AFTER CPTs are loaded (priority 12 > default 10)
        add_action('init', [$this, 'register_taxonomies'], 12);
    }

    /**
     * Register all taxonomies and attach them to the CPT.
     */
    public function register_taxonomies(): void
    {
        // --- Location (hierarchical)
        register_taxonomy(
            'property_loc',
            [$this->cpt],
            [
                'labels' => [
                    'name'              => __('Locations', 'havenconnect'),
                    'singular_name'     => __('Location', 'havenconnect'),
                    'search_items'      => __('Search Locations', 'havenconnect'),
                    'all_items'         => __('All Locations', 'havenconnect'),
                    'parent_item'       => __('Parent Location', 'havenconnect'),
                    'parent_item_colon' => __('Parent Location:', 'havenconnect'),
                    'edit_item'         => __('Edit Location', 'havenconnect'),
                    'update_item'       => __('Update Location', 'havenconnect'),
                    'add_new_item'      => __('Add New Location', 'havenconnect'),
                    'new_item_name'     => __('New Location Name', 'havenconnect'),
                    'menu_name'         => __('Locations', 'havenconnect'),
                ],
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_in_menu'      => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'location'],
            ]
        );
        register_taxonomy_for_object_type('property_loc', $this->cpt);

        // --- Group (hierarchical)
        register_taxonomy(
            'property_group',
            [$this->cpt],
            [
                'labels' => [
                    'name'              => __('Groups', 'havenconnect'),
                    'singular_name'     => __('Group', 'havenconnect'),
                    'search_items'      => __('Search Groups', 'havenconnect'),
                    'all_items'         => __('All Groups', 'havenconnect'),
                    'parent_item'       => __('Parent Group', 'havenconnect'),
                    'parent_item_colon' => __('Parent Group:', 'havenconnect'),
                    'edit_item'         => __('Edit Group', 'havenconnect'),
                    'update_item'       => __('Update Group', 'havenconnect'),
                    'add_new_item'      => __('Add New Group', 'havenconnect'),
                    'new_item_name'     => __('New Group Name', 'havenconnect'),
                    'menu_name'         => __('Groups', 'havenconnect'),
                ],
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_in_menu'      => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'group'],
            ]
        );
        register_taxonomy_for_object_type('property_group', $this->cpt);

        // --- Features (non-hierarchical) — filled by amenities importer
        register_taxonomy(
            'hcn_feature',
            [$this->cpt],
            [
                'labels' => [
                    'name'                       => __('Features', 'havenconnect'),
                    'singular_name'              => __('Feature', 'havenconnect'),
                    'search_items'               => __('Search Features', 'havenconnect'),
                    'popular_items'              => __('Popular Features', 'havenconnect'),
                    'all_items'                  => __('All Features', 'havenconnect'),
                    'edit_item'                  => __('Edit Feature', 'havenconnect'),
                    'update_item'                => __('Update Feature', 'havenconnect'),
                    'add_new_item'               => __('Add New Feature', 'havenconnect'),
                    'new_item_name'              => __('New Feature Name', 'havenconnect'),
                    'separate_items_with_commas' => __('Separate features with commas', 'havenconnect'),
                    'add_or_remove_items'        => __('Add or remove features', 'havenconnect'),
                    'choose_from_most_used'      => __('Choose from the most used features', 'havenconnect'),
                    'menu_name'                  => __('Features', 'havenconnect'),
                ],
                'hierarchical'      => false,
                'public'            => true,
                'show_ui'           => true,
                'show_in_menu'      => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'feature'],
            ]
        );
        register_taxonomy_for_object_type('hcn_feature', $this->cpt);

        if ($this->logger) {
            $this->logger->log("Taxonomies registered: property_loc, property_group, Features (hcn_feature).");
            $this->logger->save();
        }
    }

    /**
     * Apply taxonomies from a flat tag list.
     * Expected formats we support:
     *  - "LOC:London", "LOC:GB" -> property_loc
     *  - "GROUP:ENTIREHOME", "GROUP:HOUSE" -> property_group
     *
     * Anything else is ignored here (amenities are handled in the amenities sync).
     */
    public function apply_taxonomies(int $post_id, array $tags): void
    {
        if (empty($tags)) {
            // Nothing to do, but do not wipe existing unless you intend strict mirroring.
            if ($this->logger) $this->logger->log("Tags: none for post {$post_id}.");
            return;
        }

        $loc_terms   = [];
        $group_terms = [];

        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t === '') continue;

            // Normalize
            if (stripos($t, 'LOC:') === 0) {
                $label = trim(substr($t, 4));
                if ($label !== '') $loc_terms[] = $label;

            } elseif (stripos($t, 'GROUP:') === 0) {
                $label = trim(substr($t, 6));
                if ($label !== '') $group_terms[] = $label;
            }
        }

        // Create terms as needed and assign
        if (!empty($loc_terms)) {
            $loc_terms = array_values(array_unique($loc_terms));
            foreach ($loc_terms as $label) {
                if (!term_exists($label, 'property_loc')) {
                    wp_insert_term($label, 'property_loc');
                }
            }
            wp_set_object_terms($post_id, $loc_terms, 'property_loc', false);
        }

        if (!empty($group_terms)) {
            $group_terms = array_values(array_unique($group_terms));
            foreach ($group_terms as $label) {
                if (!term_exists($label, 'property_group')) {
                    wp_insert_term($label, 'property_group');
                }
            }
            wp_set_object_terms($post_id, $group_terms, 'property_group', false);
        }

        if ($this->logger) {
            $msg = [];
            if (!empty($loc_terms))   $msg[] = "Locations → " . implode(', ', $loc_terms);
            if (!empty($group_terms)) $msg[] = "Groups → " . implode(', ', $group_terms);
            if ($msg) $this->logger->log("Taxonomy apply for post {$post_id}: " . implode(' | ', $msg));
        }
    }
}