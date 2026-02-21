<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Taxonomy_Handler {

  private string $cpt;
  private $logger;

  /**
   * Constructor supports BOTH calling styles:
   *  1) new HavenConnect_Taxonomy_Handler($logger)                 // your current havenconnect.php usage
   *  2) new HavenConnect_Taxonomy_Handler('hcn_property', $logger) // explicit CPT
   */
  public function __construct($cpt_or_logger = 'hcn_property', $maybe_logger = null) {

    // Legacy style: first argument is logger object
    if (is_object($cpt_or_logger) && class_exists('HavenConnect_Logger') && ($cpt_or_logger instanceof HavenConnect_Logger)) {
      $this->logger = $cpt_or_logger;
      $this->cpt    = 'hcn_property';
    } else {
      $this->cpt    = (is_string($cpt_or_logger) && $cpt_or_logger !== '') ? $cpt_or_logger : 'hcn_property';
      $this->logger = $maybe_logger;
    }

    // Register AFTER CPTs are loaded
    add_action('init', [$this, 'register_taxonomies'], 12);
  }

  /**
   * Register taxonomies and attach them to the CPT.
   */
  public function register_taxonomies(): void {

    // --- Location (hierarchical)
    register_taxonomy(
      'property_loc',
      [$this->cpt],
      [
        'labels' => [
          'name'          => __('Locations', 'havenconnect'),
          'singular_name' => __('Location', 'havenconnect'),
        ],
        'hierarchical'      => true,
        'public'            => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_admin_column' => true,
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
          'name'          => __('Groups', 'havenconnect'),
          'singular_name' => __('Group', 'havenconnect'),
        ],
        'hierarchical'      => true,
        'public'            => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'query_var'         => true,
        'rewrite'           => ['slug' => 'group'],
      ]
    );
    register_taxonomy_for_object_type('property_group', $this->cpt);

    // --- Features (non-hierarchical)
    register_taxonomy(
      'hcn_feature',
      [$this->cpt],
      [
        'labels' => [
          'name'          => __('Features', 'havenconnect'),
          'singular_name' => __('Feature', 'havenconnect'),
        ],
        'hierarchical'      => false,
        'public'            => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'query_var'         => true,
        'rewrite'           => ['slug' => 'feature'],
      ]
    );
    register_taxonomy_for_object_type('hcn_feature', $this->cpt);

    if ($this->logger) {
      $this->logger->log("Taxonomies registered for CPT {$this->cpt}: property_loc, property_group, hcn_feature.");
      $this->logger->save();
    }
  }

  /**
   * Apply taxonomies from Hostfully tag strings.
   *
   * Supported formats:
   *  - "[l] Cotswolds" -> property_loc
   *  - "[g] hot tub"  -> property_group
   * Back-compat:
   *  - "LOC:Cotswolds"
   *  - "GROUP:hot tub"
   */
  public function apply_taxonomies(int $post_id, array $tags): void {

    if (empty($tags)) {
      if ($this->logger) $this->logger->log("Tags: none for post {$post_id}.");
      return;
    }

    $loc_terms   = [];
    $group_terms = [];

    foreach ($tags as $t) {
      if (!is_string($t)) continue;
      $t = trim($t);
      if ($t === '') continue;

      // [l] Location
      if (preg_match('/^\[(l|L)\]\s*(.+)$/', $t, $m)) {
        $label = trim($m[2]);
        if ($label !== '') $loc_terms[] = $label;
        continue;
      }

      // [g] Group
      if (preg_match('/^\[(g|G)\]\s*(.+)$/', $t, $m)) {
        $label = trim($m[2]);
        if ($label !== '') $group_terms[] = $label;
        continue;
      }

      // Back-compat: LOC:
      if (stripos($t, 'LOC:') === 0) {
        $label = trim(substr($t, 4));
        if ($label !== '') $loc_terms[] = $label;
        continue;
      }

      // Back-compat: GROUP:
      if (stripos($t, 'GROUP:') === 0) {
        $label = trim(substr($t, 6));
        if ($label !== '') $group_terms[] = $label;
        continue;
      }

      // Everything else ignored here (e.g. "Featured")
    }

    // Assign Locations
    if (!empty($loc_terms)) {
      $loc_terms = array_values(array_unique($loc_terms));
      foreach ($loc_terms as $label) {
        if (!term_exists($label, 'property_loc')) {
          wp_insert_term($label, 'property_loc');
        }
      }
      wp_set_object_terms($post_id, $loc_terms, 'property_loc', false);
    }

    // Assign Groups
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
      $this->logger->save();
    }
  }
}