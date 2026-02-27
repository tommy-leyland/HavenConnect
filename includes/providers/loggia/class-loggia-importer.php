<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Loggia_Importer {

  /** @var HavenConnect_Logger */
  private $logger;

  /** @var HavenConnect_Photo_Sync */
  private $photos;

  public function __construct($logger, $photo_sync) {
    $this->logger = $logger;
    $this->photos = $photo_sync;
  }

  // -----------------------------
  // Public API
  // -----------------------------

  /**
   * Build a list of Loggia property IDs from the connections list.
   * NOTE: This is safe to use during queue build (AJAX start),
   * but we do NOT use this inside import_one() anymore.
   */
  public function list_property_ids(HavenConnect_Loggia_Client $client, string $page_id, string $locale = 'en'): array {
    $list = $this->safe_call(fn() => $client->list_properties_connections($page_id, $locale, 100, 0, 3, true), 'list_properties_connections');
    if (!is_array($list)) return [];
    $rows = $list['properties'] ?? [];
    if (!is_array($rows)) return [];

    $ids = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $id = $row['property_id'] ?? $row['id'] ?? null;
      if ($id) $ids[] = (string)$id;
    }
    return $ids;
  }

  /**
   * Import a single Loggia property into WP.
   * Returns post_id.
   */
  public function import_one(HavenConnect_Loggia_Client $client, string $property_id, string $page_id, string $locale): int {

    // ✅ minimal, useful log
    $this->log("Loggia: importing property_id={$property_id} (page_id={$page_id}, locale={$locale})");
    $this->flush();

    // ---- Fetch payloads (best effort; failures should not kill import) ----
    $summary  = $this->safe_call(fn() => $client->get_summary($property_id, $page_id, $locale), 'get_summary');
    $contentP = $this->safe_call(fn() => $client->get_content($property_id, $page_id, $locale), 'get_content');
    $desc     = $this->safe_call(fn() => $client->get_descriptions($property_id, $page_id, $locale), 'get_descriptions');
    $media    = $this->safe_call(fn() => $client->get_media($property_id, $page_id, $locale, 'thumb', 1), 'get_media');
    $loc      = $this->safe_call(fn() => $client->get_location($property_id, $page_id, $locale), 'get_location');
    $features = $this->safe_call(fn() => $client->get_features_by_group($property_id, $page_id, $locale), 'get_features_by_group');

    // ✅ one compact payload status line
    $this->log(sprintf(
      'Loggia: %s payloads — summary:%s content:%s desc:%s media:%s loc:%s features:%s',
      $property_id,
      $this->okflag($summary),
      $this->okflag($contentP),
      $this->okflag($desc),
      $this->okflag($media),
      $this->okflag($loc),
      $this->okflag($features)
    ));

    $this->flush();

    // ---- Extract core fields ----
    $title   = $this->extract_title($property_id, $summary, $contentP, $desc);
    $content = $this->extract_content($contentP, $desc);
    $excerpt = $this->extract_excerpt($desc, $content);

    // Upsert WP post
    $post_id = $this->upsert_post($property_id, $title, $content, $excerpt);

    // ✅ one clean summary line
    $this->log(sprintf('Loggia: "%s" → post_id=%d', $title, (int)$post_id));

    // ----------------------------------------------------
    // Hostfully-equivalent core fields (meta keys match Hostfully importer)
    // ----------------------------------------------------
    if (is_array($contentP) && isset($contentP['details']) && is_array($contentP['details'])) {
      $d = $contentP['details'];

      // Guests -> Hostfully meta key "sleeps"
      $sleeps = null;
      if (isset($d['capacity_ideal']) && is_numeric($d['capacity_ideal'])) $sleeps = (int)$d['capacity_ideal'];
      elseif (isset($d['capacity_max']) && is_numeric($d['capacity_max'])) $sleeps = (int)$d['capacity_max'];

      // Bedrooms
      $bedrooms = (isset($d['bedrooms_num']) && is_numeric($d['bedrooms_num'])) ? (int)$d['bedrooms_num'] : null;

      // Bathrooms
      $bathrooms = (isset($d['bathrooms_num']) && is_numeric($d['bathrooms_num'])) ? (float)$d['bathrooms_num'] : null;

      // Beds
      $beds = null;
      if (isset($d['bedrooms']) && is_string($d['bedrooms']) && preg_match('/\d+/', $d['bedrooms'], $m)) {
        $beds = (int)$m[0];
      }

      // Address fields
      $addr1 = isset($d['address']) && is_string($d['address']) ? trim($d['address']) : null;
      $postcode = isset($d['zip']) && is_string($d['zip']) ? trim($d['zip']) : null;

      // City/State
      $city = isset($d['city_name']) && is_string($d['city_name']) ? trim($d['city_name']) : null;
      $state = null;

      // Address line 2 from location.address_02 if present
      $addr2 = null;
      if (isset($contentP['location']) && is_array($contentP['location'])) {
        $addr2 = isset($contentP['location']['address_02']) && is_string($contentP['location']['address_02']) ? trim($contentP['location']['address_02']) : null;
      }

      $map = [
        'sleeps' => $sleeps,
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathrooms,
        'beds' => $beds,
        'address_line1' => $addr1,
        'address_line2' => $addr2,
        'city' => $city,
        'state' => $state,
        'postcode' => $postcode,
      ];

      foreach ($map as $key => $val) {
        if ($val !== null && $val !== '' && $val !== []) {
          $this->set_meta_unless_locked($post_id, $key, $val);
        } else {
          delete_post_meta($post_id, $key);
        }
      }
    }

    // ---- Save stable metas ----
    update_post_meta($post_id, 'hcn_source', 'loggia');
    update_post_meta($post_id, 'loggia_property_id', $property_id);
    update_post_meta($post_id, 'loggia_page_id_used', $page_id);
    update_post_meta($post_id, 'loggia_locale_used', $locale);
    update_post_meta($post_id, 'loggia_last_imported_at', current_time('mysql'));

    // Save lightweight raw payload snapshots
    $this->save_payload_snapshot($post_id, 'loggia_summary_json', $summary);
    $this->save_payload_snapshot($post_id, 'loggia_content_json', $contentP);
    $this->save_payload_snapshot($post_id, 'loggia_desc_json', $desc);
    $this->save_payload_snapshot($post_id, 'loggia_loc_json', $loc);

    // ---- Media URLs -> gallery meta ----
    $urls = $this->extract_media_urls($media);
    if (!empty($urls)) {
      update_post_meta($post_id, '_hcn_gallery_urls', array_values($urls));
      update_post_meta($post_id, '_hcn_featured_image_url', $urls[0]);

      $thumb_url = $this->extract_thumb_url_from_media($media);
      if ($thumb_url) {
        update_post_meta($post_id, '_hcn_featured_thumb_url', $thumb_url);
      } else {
        update_post_meta($post_id, '_hcn_featured_thumb_url', $urls[0]);
      }
      $this->log("Loggia: saved " . count($urls) . " media URLs.");
    } else {
      $this->log("Loggia: no media URLs detected.");
    }

    // ---- Location -> meta (best effort) ----
    [$lat, $lng] = $this->extract_lat_lng($loc);
    if ($lat !== null) update_post_meta($post_id, 'latitude', (float)$lat);
    if ($lng !== null) update_post_meta($post_id, 'longitude', (float)$lng);

    // ---- Features -> store raw for now ----
    if (is_array($features)) {
      $this->save_payload_snapshot($post_id, 'loggia_features_json', $features);
    }

    $this->flush();
    return (int)$post_id;
  }

  // -----------------------------
  // Logging helpers
  // -----------------------------
  private function okflag($v): string {
    if (is_array($v)) return 'ok';
    if (is_object($v)) return 'ok';
    if (is_string($v) && trim($v) !== '') return 'ok';
    return 'null';
  }

  private function log(string $msg): void {
    if ($this->logger) {
      $this->logger->log($msg);
    }
  }

  private function flush(): void {
    if ($this->logger && method_exists($this->logger, 'save')) {
      $this->logger->save();
    }
  }

  private function safe_call(callable $fn, string $label) {
    try {
      return $fn();
    } catch (Throwable $e) {
      // Minimal risk: log once per failure, but don’t blow up import
      $this->log("Loggia: {$label} failed: " . $e->getMessage());
      $this->flush();
      return null;
    }
  }

  // -----------------------------
  // Internals: extraction + WP upsert
  // -----------------------------
  // Everything below here should match your existing file (unchanged logic).
  // I’m leaving your existing helpers as-is in your repo; if you paste this over,
  // keep the rest of your original methods below this point.

  private function extract_title(string $property_id, $summary, $contentP, $desc): string {
    $candidates = [
      $this->get_any($summary, ['property_title','page_title','title','name']),
      $this->get_any($contentP, ['property_title','page_title','title','name']),
      $this->get_any($desc, ['property_title','page_title','title','name']),
      $this->get_any($this->get_any($summary, ['data']), ['property_title','page_title','title','name']),
      $this->get_any($this->get_any($contentP, ['data']), ['property_title','page_title','title','name']),
    ];
    foreach ($candidates as $t) {
      if (is_string($t) && trim($t) !== '') return trim($t);
    }
    return "Loggia {$property_id}";
  }

  private function extract_content($contentP, $desc): string {
    if (!is_array($contentP)) return '';
    $d = $contentP['details'] ?? null;
    if (!is_array($d)) return '';
    $html = $d['description'] ?? '';
    if (is_string($html) && trim($html) !== '') return $html;
    $html = $d['whitelabel_description'] ?? '';
    return is_string($html) ? $html : '';
  }

  private function extract_excerpt($desc, string $content): string {
    $d = null;
    if (is_array($desc) && isset($desc['descriptions']) && is_array($desc['descriptions'])) {
      $d = $desc['descriptions'];
    }
    if (is_array($d)) {
      $sum = $d['accommodations_summary'] ?? '';
      if (is_string($sum) && trim($sum) !== '') return trim($sum);
      $brief = $d['desc_brief'] ?? '';
      if (is_string($brief) && trim($brief) !== '') {
        $plain = trim(wp_strip_all_tags($brief));
        return mb_substr($plain, 0, 200);
      }
    }
    $plain = trim(wp_strip_all_tags($content));
    if ($plain === '') return '';
    return mb_substr($plain, 0, 200);
  }

  // --- The remaining methods are unchanged from your existing file ---
  // extract_media_urls(), extract_thumb_url_from_media(), extract_lat_lng(), upsert_post(),
  // set_meta_unless_locked(), save_payload_snapshot(), get_any(), walk(), etc.
  //
  // IMPORTANT: Paste your existing versions below if your editor replaces the whole file.
}