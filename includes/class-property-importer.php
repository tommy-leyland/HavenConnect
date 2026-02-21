<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Property_Importer {

  private $api;
  private $tax;
  private $photos;
  private $logger;

  public function __construct($api_client, $taxonomy_handler, $photo_sync, $logger) {
    $this->api    = $api_client;
    $this->tax    = $taxonomy_handler;
    $this->photos = $photo_sync;
    $this->logger = $logger;
  }

  /**
   * Legacy "Run Import" (if you still call it anywhere)
   */
  public function run_import(string $apiKey, string $agencyUid): bool {
    if (method_exists($this->logger, 'clear')) $this->logger->clear();
    $this->logger->log("Import started…");

    $props  = [];
    $source = 'all';

    // Prefer ALL as a clean baseline
    if (method_exists($this->api, 'get_properties_by_agency')) {
      $props = $this->api->get_properties_by_agency($apiKey, $agencyUid);
      $source = 'all';
    }

    // Optional: fallback to featured if "all" not available
    if ((!is_array($props) || empty($props)) && method_exists($this->api, 'get_featured_list')) {
      $props = $this->api->get_featured_list($apiKey, $agencyUid);
      $source = 'featured';
    }

    $count = is_array($props) ? count($props) : 0;
    $this->logger->log("Found {$count} properties via {$source}.");

    if (!is_array($props)) $props = [];

    foreach ($props as $p) {
      if (!is_array($p)) continue;
      $uid = $p['uid'] ?? ($p['UID'] ?? null);
      if (!$uid) {
        $this->logger->log("Skipped property without UID.");
        continue;
      }
      $this->import_single_property($apiKey, $uid, $p);
    }

    $this->logger->log("Import complete.");
    if (method_exists($this->logger, 'save')) $this->logger->save();

    return true;
  }

  /**
   * AJAX importer calls this (your class-import-ajax.php uses this)
   */
  public function import_property_from_featured(string $apiKey, array $p): int {
    $uid = $p['uid'] ?? ($p['UID'] ?? null);
    if (!$uid) {
      $this->logger->log("Single import: missing UID.");
      if (method_exists($this->logger, 'save')) $this->logger->save();
      return 0;
    }
    return $this->import_single_property($apiKey, $uid, $p);
  }

  /**
   * Canonical per-property import
   */
  private function import_single_property(string $apiKey, string $uid, array $data): int {

    $title = $data['name'] ?? ($data['Name'] ?? 'Untitled');
    $this->logger->log("Importing {$title} ({$uid}) …");

    // 0) Fetch full property details (v3.2) and merge over list payload
    if (method_exists($this->api, 'get_property_details')) {
      $details = $this->api->get_property_details($apiKey, $uid);
      $details_p = [];

      if (is_array($details)) {
        $details_p = (isset($details['property']) && is_array($details['property']))
          ? $details['property']
          : $details;
      }

      if (!empty($details_p)) {
        $data = array_merge($data, $details_p);
        $this->logger->log("Property details loaded for {$uid}.");
      } else {
        $this->logger->log("Property details empty for {$uid} (continuing with list payload).");
      }
    }

    // 1) Upsert post
    $post_id = $this->upsert_post($uid, $data);
    if (!$post_id) {
      $this->logger->log("Post upsert failed for {$uid}.");
      if (method_exists($this->logger, 'save')) $this->logger->save();
      return 0;
    }

    // 1b) Descriptions -> post_content / post_excerpt
    $this->import_property_descriptions($apiKey, $uid, $post_id);

    // 2) Tags -> taxonomies
    $tags_raw = method_exists($this->api, 'get_property_tags') ? $this->api->get_property_tags($apiKey, $uid) : [];
    $tags = $this->normalize_tags($tags_raw);

    if (empty($tags)) {
      $fallback = $this->build_fallback_tags_from_property($data);
      if (!empty($fallback)) {
        $this->logger->log("Tags: using fallback for {$uid}: " . implode(', ', $fallback));
        $tags = $fallback;
      } else {
        $this->logger->log("Tags: none for {$uid}.");
      }
    }

    if ($this->tax && method_exists($this->tax, 'apply_taxonomies')) {
      $this->tax->apply_taxonomies($post_id, $tags);
    }

    // 3) Photos
    $photo_payload = method_exists($this->api, 'get_property_photos') ? $this->api->get_property_photos($apiKey, $uid) : [];
    if (!empty($this->photos) && method_exists($this->photos, 'sync_from_payload')) {
      $this->photos->sync_from_payload($post_id, (array)$photo_payload);
    }

    // 4) Meta
    $this->update_meta($post_id, $data);

    // 5) Amenities -> Features taxonomy
    $this->sync_property_amenities($apiKey, $uid, $post_id);

    // 6) Availability (your importer clamps internally for test)
    if (isset($GLOBALS['havenconnect']['availability'])) {
      try {
        $from = gmdate('Y-m-d');
        $to   = gmdate('Y-m-d', strtotime('+365 days'));
        $GLOBALS['havenconnect']['availability']->sync_property_calendar($apiKey, $uid, $post_id, $from, $to);
      } catch (\Throwable $e) {
        $this->logger->log("Availability error for {$uid}: " . $e->getMessage());
      }
    }

    $this->logger->log("Imported {$title} (post_id={$post_id})");
    if (method_exists($this->logger, 'save')) $this->logger->save();

    return $post_id;
  }

    private function set_meta_unless_locked(int $post_id, string $meta_key, $value): void {
        // If the field is locked in admin UI, don't overwrite it during import
        $locked = get_post_meta($post_id, "{$meta_key}_locked", true);
        if ($locked === '1') {
            $this->logger->log("Meta locked: skipping {$meta_key} for post_id={$post_id}");
            return;
        }

        if ($value !== null && $value !== '' && $value !== []) {
            update_post_meta($post_id, $meta_key, $value);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }

  /**
   * Descriptions import:
   * - long -> post_content
   * - short -> post_excerpt
   *
   * Also stores full payload in meta (for debugging/mapping): hcn_property_descriptions
   */
    private function import_property_descriptions(string $apiKey, string $propertyUid, int $post_id): void {
    if (!method_exists($this->api, 'get_property_descriptions')) return;

    $payload = $this->api->get_property_descriptions($apiKey, $propertyUid);
    if (!is_array($payload) || empty($payload)) {
        $this->logger->log("Descriptions: none returned for {$propertyUid}.");
        return;
    }

    // Optional: remove the big JSON blob once you're confident it works
    // (keeping it can bloat your DB with 300 properties)
    // update_post_meta($post_id, 'hcn_property_descriptions', wp_json_encode($payload));

    $record = $this->pick_best_description_record($payload);
    if (empty($record)) {
        $this->logger->log("Descriptions: payload returned but no usable record for {$propertyUid}.");
        return;
    }

    $this->logger->log("Descriptions: record keys for {$propertyUid}: " . implode(',', array_keys($record)));

    // Hostfully keys we care about
    $summary = trim((string)($record['summary'] ?? ''));
    $short   = trim((string)($record['shortSummary'] ?? ''));

    // Normalise Hostfully "nn" line breaks
    $norm = function($v) {
        if (!is_string($v)) return '';
        $v = str_replace("nn", "\n\n", $v);
        return trim($v);
    };

    $summary = $norm($summary);
    $short   = $norm($short);

    // ✅ WP content = summary ONLY
    $content = $summary;

    // ✅ WP excerpt = shortSummary fallback to summary
    $excerpt = ($short !== '') ? $short : $summary;

    // ✅ WP Custom Fields (post meta) for sections (NO house manual)
    $meta_map = [
        'property_space'         => 'space',
        'property_neighbourhood' => 'neighbourhood',
        'property_access'        => 'access',
        'property_transit'       => 'transit',
        'property_interaction'   => 'interaction',
        'property_notes'         => 'notes',
    ];

    foreach ($meta_map as $meta_key => $hostfully_key) {
        $val = $norm($record[$hostfully_key] ?? '');
        $this->set_meta_unless_locked($post_id, $meta_key, $val);
    }

    $this->logger->log("Descriptions: {$propertyUid} summary_len=" . strlen($summary) . " short_len=" . strlen($short));

    // Write to WP post_content / excerpt
    $update = ['ID' => $post_id];

    if ($content !== '') {
        $update['post_content'] = wp_kses_post(wpautop($content));
    }
    // else: leave existing post_content untouched

    if ($excerpt !== '') {
        $update['post_excerpt'] = wp_strip_all_tags($excerpt);
    } else {
        $update['post_excerpt'] = '';
    }

    $res = wp_update_post($update, true);
    if (is_wp_error($res)) {
        $this->logger->log("Descriptions: wp_update_post error for {$propertyUid}: " . $res->get_error_message());
        return;
    }

    // Read-back proof
    $saved = get_post_field('post_content', $post_id);
    $this->logger->log("Descriptions: saved post_content_len=" . strlen((string)$saved) . " for {$propertyUid}");
    }

  /**
   * Your payload starts like:
   * {"propertyDescriptions":[{...}]}
   */
  private function pick_best_description_record(array $payload): array {
    $list = [];

    // Most likely key from your snippet
    if (!empty($payload['propertyDescriptions']) && is_array($payload['propertyDescriptions'])) {
      $list = $payload['propertyDescriptions'];
    } else {
      // fallback common shapes
      foreach (['descriptions','items','data'] as $k) {
        if (!empty($payload[$k]) && is_array($payload[$k])) { $list = $payload[$k]; break; }
      }
      if (empty($list) && isset($payload[0]) && is_array($payload[0])) $list = $payload;
    }

    if (empty($list)) return [];

    // Prefer UK English then any English then first
    $prefer = ['en_GB','en-GB','en_US','en-US','en'];
    foreach ($prefer as $loc) {
      foreach ($list as $row) {
        if (!is_array($row)) continue;
        $rowLoc = (string)($row['locale'] ?? $row['language'] ?? '');
        if ($rowLoc && strcasecmp($rowLoc, $loc) === 0) return $row;
      }
    }

    return is_array($list[0]) ? $list[0] : [];
  }

  /**
   * Upsert the CPT post by _havenconnect_uid
   */
  private function upsert_post(string $uid, array $data): int {
    $title = sanitize_text_field($data['name'] ?? ($data['Name'] ?? 'Untitled'));

    $existing = get_posts([
      'post_type'      => 'hcn_property',
      'meta_key'       => '_havenconnect_uid',
      'meta_value'     => $uid,
      'post_status'    => ['publish','draft','pending','private'],
      'fields'         => 'ids',
      'numberposts'    => 1,
      'no_found_rows'  => true,
    ]);

    $post_id = $existing ? (int)$existing[0] : 0;

    $postarr = [
      'ID'          => $post_id,
      'post_type'   => 'hcn_property',
      'post_title'  => $title,
      'post_status' => $post_id ? get_post_status($post_id) : 'publish',
    ];

    $new_id = $post_id ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);

    if (is_wp_error($new_id)) {
      $this->logger->log("Post error for UID {$uid}: " . $new_id->get_error_message());
      return 0;
    }

    $post_id = (int)$new_id;
    update_post_meta($post_id, '_havenconnect_uid', $uid);

    return $post_id;
  }

  /**
   * Extract tags (strings) from any nested shape.
   */
  private function normalize_tags($raw): array {
    if (empty($raw)) return [];

    $flat = [];
    $walk = function($v) use (&$flat, &$walk) {
      if (is_string($v)) { $flat[] = trim($v); return; }
      if (is_array($v)) {
        if (isset($v['name']) && is_string($v['name'])) { $flat[] = trim($v['name']); return; }
        foreach ($v as $vv) $walk($vv);
      }
    };

    $walk($raw);

    $flat = array_filter(array_map('trim', $flat));
    return array_values(array_unique($flat));
  }

  /**
   * Optional fallback tags (if Hostfully tags are missing)
   */
  private function build_fallback_tags_from_property(array $p): array {
    $tags = [];

    $addr = (array)($p['address'] ?? []);
    $city = trim((string)($addr['city'] ?? ''));
    $state = trim((string)($addr['state'] ?? ''));
    $country = trim((string)($addr['countryCode'] ?? ''));

    if ($city !== '') $tags[] = 'LOC:' . $city;
    if ($state !== '') $tags[] = 'LOC:' . $state;
    if ($country !== '') $tags[] = 'LOC:' . $country;

    $listingType  = trim((string)($p['listingType'] ?? ''));
    $propertyType = trim((string)($p['propertyType'] ?? ''));

    if ($listingType !== '')  $tags[] = 'GROUP:' . $listingType;
    if ($propertyType !== '') $tags[] = 'GROUP:' . $propertyType;

    return array_values(array_unique($tags));
  }

  /**
   * Meta mapping (keep yours, adjust as needed)
   */
  private function update_meta(int $post_id, array $p): void {
    $addr  = (array)($p['address'] ?? []);
    $avail = (array)($p['availability'] ?? []);
    $area  = (array)($p['area'] ?? []);

    $to_int = fn($v) => is_numeric($v) ? (int)$v : null;
    $to_float = fn($v) => is_numeric($v) ? (float)$v : null;

    $map = [
      'bedrooms'      => $to_int($p['bedrooms'] ?? null),
      'bathrooms'     => $to_float($p['bathrooms'] ?? null),
      'beds'          => $to_int($p['beds'] ?? null),
      'sleeps'        => $to_int($avail['maxGuests'] ?? ($p['maxGuests'] ?? null)),
      'address_line1' => $addr['address'] ?? null,
      'address_line2' => $addr['address2'] ?? null,
      'city'          => $addr['city'] ?? null,
      'state'         => $addr['state'] ?? null,
      'postcode'      => $addr['zipCode'] ?? null,
      'country_code'  => $addr['countryCode'] ?? null,
      'latitude'      => $to_float($addr['latitude'] ?? null),
      'longitude'     => $to_float($addr['longitude'] ?? null),
      'property_type' => $p['propertyType'] ?? null,
      'listing_type'  => $p['listingType'] ?? null,
      'room_type'     => $p['roomType'] ?? null,
      'area_size'     => $to_float($area['size'] ?? null),
      'area_unit'     => $area['unitType'] ?? null,
      'license_number'=> $p['rentalLicenseNumber'] ?? null,
      'license_expiry'=> $p['rentalLicenseExpirationDate'] ?? null,
    ];

    foreach ($map as $key => $value) {
      if ($value !== null && $value !== '' && $value !== []) {
        $this->set_meta_unless_locked($post_id, $key, $value);
      } else {
        delete_post_meta($post_id, $key);
      }
    }
  }

  /**
   * Amenities -> Features taxonomy
   */
  private function amenity_display_name(string $code): string {
    $clean = preg_replace('/^(HAS_|IS_)/', '', strtoupper($code));
    $clean = str_replace('_', ' ', $clean);
    return ucwords(strtolower($clean));
  }

  private function sync_property_amenities(string $apiKey, string $propertyUid, int $post_id): void {
    if (!method_exists($this->api, 'get_property_amenities')) return;

    $rows = $this->api->get_property_amenities($apiKey, $propertyUid);
    if (empty($rows) || !is_array($rows)) {
      $this->logger->log("Amenities: none for {$propertyUid}");
      return;
    }

    $terms = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $code = isset($row['amenity']) ? trim((string)$row['amenity']) : '';
      if ($code === '') continue;

      $label = $this->amenity_display_name($code);
      if ($label === '') continue;

      if (!term_exists($label, 'hcn_feature')) {
        $res = wp_insert_term($label, 'hcn_feature');
        if (!is_wp_error($res)) $this->logger->log("Amenity term added: {$label}");
      }
      $terms[] = $label;
    }

    if (!empty($terms)) {
      wp_set_object_terms($post_id, array_values(array_unique($terms)), 'hcn_feature', false);
      $this->logger->log("Amenities assigned to post {$post_id}: " . implode(', ', array_values(array_unique($terms))));
    }
  }
}