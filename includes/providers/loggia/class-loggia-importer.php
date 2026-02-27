<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Loggia_Importer {
  /** @var HavenConnect_Logger|null */
  private $logger;
  /** @var HavenConnect_Photo_Sync|null */
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
  public function import_one(
    HavenConnect_Loggia_Client $client,
    string $property_id,
    string $page_id,
    string $locale
  ): int {
    $this->log("Loggia importer: import_one started property_id={$property_id}, page_id={$page_id}, locale={$locale}");
    $mark = function($msg) {
  if ($this->logger) { $this->logger->log($msg); $this->logger->save(); }
};
    $this->flush();

    // ---- Fetch payloads (each is optional; failures should not kill import) ----

    $mark("Loggia importer: calling get_summary");
    $summary  = $client->get_summary($property_id, $page_id, $locale);
    $mark("Loggia importer: got summary type=" . gettype($summary));

    $mark("Loggia importer: calling get_content");
    $contentP = $client->get_content($property_id, $page_id, $locale);
    $mark("Loggia importer: got content type=" . gettype($contentP));

    $mark("Loggia importer: calling get_descriptions");
    $desc     = $client->get_descriptions($property_id, $page_id, $locale);
    $mark("Loggia importer: got desc type=" . gettype($desc));

    $mark("Loggia importer: calling get_media");
    $media    = $client->get_media($property_id, $page_id, $locale, 'thumb', 1);
    $mark("Loggia importer: got media type=" . gettype($media));

    $mark("Loggia importer: calling get_location");
    $loc      = $client->get_location($property_id, $page_id, $locale);
    $mark("Loggia importer: got loc type=" . gettype($loc));

    $mark("Loggia importer: calling get_features_by_group");
    $features = $client->get_features_by_group($property_id, $page_id, $locale);
    $mark("Loggia importer: got features type=" . gettype($features));

    $this->log("Loggia importer: payload types summary=" . gettype($summary) .
      " content=" . gettype($contentP) .
      " desc=" . gettype($desc) .
      " media=" . gettype($media) .
      " loc=" . gettype($loc) .
      " features=" . gettype($features)
    );
    $this->flush();

    // ---- Extract core fields ----
    $title   = $this->extract_title($property_id, $summary, $contentP, $desc);
    $content = $this->extract_content($contentP, $desc);
    $excerpt = $this->extract_excerpt($desc, $content);

    // Upsert WP post
    $post_id = $this->upsert_post($property_id, $title, $content, $excerpt);

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

      // Beds (Loggia gives "bedrooms" like "2 beds" as a string in your payload)
      $beds = null;
      if (isset($d['bedrooms']) && is_string($d['bedrooms']) && preg_match('/\d+/', $d['bedrooms'], $m)) {
        $beds = (int)$m[0];
      }

      // Address fields
      $addr1 = isset($d['address']) && is_string($d['address']) ? trim($d['address']) : null;
      $postcode = isset($d['zip']) && is_string($d['zip']) ? trim($d['zip']) : null;

      // City/State: Loggia payload uses city_name="Anglesey" (UK county-ish).
      // We'll map that to "city" and leave "state" empty unless you decide a rule.
      $city  = isset($d['city_name']) && is_string($d['city_name']) ? trim($d['city_name']) : null;
      $state = null;

      // Address line 2 from location.address_02 if present
      $addr2 = null;
      if (isset($contentP['location']) && is_array($contentP['location'])) {
        $addr2 = isset($contentP['location']['address_02']) && is_string($contentP['location']['address_02'])
          ? trim($contentP['location']['address_02'])
          : null;
      }

      // Save (match Hostfully keys)
      $map = [
        'sleeps'        => $sleeps,
        'bedrooms'      => $bedrooms,
        'bathrooms'     => $bathrooms,
        'beds'          => $beds,
        'address_line1' => $addr1,
        'address_line2' => $addr2,
        'city'          => $city,
        'state'         => $state,
        'postcode'      => $postcode,
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

    // Save lightweight raw payload snapshots (helpful for debugging/mapping later)
    // Keep them small to avoid bloating postmeta.
    $this->save_payload_snapshot($post_id, 'loggia_summary_json', $summary);
    $this->save_payload_snapshot($post_id, 'loggia_content_json', $contentP);
    $this->save_payload_snapshot($post_id, 'loggia_desc_json', $desc);
    $this->save_payload_snapshot($post_id, 'loggia_loc_json', $loc);

	// ---- Media URLs -> gallery meta ----
	$urls = $this->extract_media_urls($media);
	if (!empty($urls)) {
		update_post_meta($post_id, '_hcn_gallery_urls', array_values($urls));
		update_post_meta($post_id, '_hcn_featured_image_url', $urls[0]);

		// Thumb: extract from media response directly (get_media requests sizes=thumb)
		$thumb_url = $this->extract_thumb_url_from_media($media);
		if ($thumb_url) {
			update_post_meta($post_id, '_hcn_featured_thumb_url', $thumb_url);
			$this->log("Loggia importer: thumb URL written: {$thumb_url}");
		} else {
			// Fallback: use first gallery URL so the meta is always populated
			update_post_meta($post_id, '_hcn_featured_thumb_url', $urls[0]);
			$this->log("Loggia importer: no thumb URL found, falling back to first gallery URL.");
		}

		$this->log("Loggia importer: saved " . count($urls) . " media URLs.");
	} else {
		$this->log("Loggia importer: no media URLs detected.");
	}

    // ---- Location -> meta (best effort) ----
    [$lat, $lng] = $this->extract_lat_lng($loc);
    if ($lat !== null) update_post_meta($post_id, 'latitude', (float)$lat);
    if ($lng !== null) update_post_meta($post_id, 'longitude', (float)$lng);

    // ---- Features -> store raw for now (taxonomy mapping later) ----
    if (is_array($features)) {
      $this->save_payload_snapshot($post_id, 'loggia_features_json', $features);
    }

    $this->flush();
    return (int)$post_id;
  }

  // -----------------------------
  // Internals: extraction
  // -----------------------------

  private function extract_title(string $property_id, $summary, $contentP, $desc): string {
    // Known likely keys from Loggia
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

    // Prefer whitelabel website description
    $html = $d['description'] ?? '';
    if (is_string($html) && trim($html) !== '') return $html;

    // Fallback: brief description
    $html = $d['whitelabel_description'] ?? '';
    return is_string($html) ? $html : '';
  }

  private function extract_excerpt($desc, string $content): string {
    // Prefer accommodations_summary from the content payload if available via desc endpoint
    $d = null;
    if (is_array($desc) && isset($desc['descriptions']) && is_array($desc['descriptions'])) {
      $d = $desc['descriptions'];
    }

    if (is_array($d)) {
      $sum = $d['accommodations_summary'] ?? '';
      if (is_string($sum) && trim($sum) !== '') return trim($sum);

      // Next best: desc_brief as plain text
      $brief = $d['desc_brief'] ?? '';
      if (is_string($brief) && trim($brief) !== '') {
        $plain = trim(wp_strip_all_tags($brief));
        return mb_substr($plain, 0, 200);
      }
    }

    // Fallback from content
    $plain = trim(wp_strip_all_tags($content));
    if ($plain === '') return '';
    return mb_substr($plain, 0, 200);
  }

  private function extract_media_urls($media): array {
    // Prefer Loggia "album" structure from the content payload if present
    // but this function only receives $media, so we handle common shapes:
    // 1) album[] with "image"
    // 2) items/images[] with "image" or "url"
    // 3) fallback to recursive URL scan but only keep the "main" image urls

    $urls = [];

    // Case 1: album array
    if (is_array($media) && !empty($media['album']) && is_array($media['album'])) {
      $album = $media['album'];
      usort($album, function ($a, $b) {
        return (int)($a['image_sort'] ?? 0) <=> (int)($b['image_sort'] ?? 0);
      });

      foreach ($album as $img) {
        if (!is_array($img)) continue;
        $u = $img['image'] ?? '';
        if (is_string($u) && $u !== '') $urls[] = $u;
      }

      return array_values(array_unique($urls));
    }

    // Case 2: other list shapes
    $list = null;
    if (is_array($media)) {
      $list = $media['images'] ?? $media['items'] ?? $media['media'] ?? null;
    }
    if (is_array($list)) {
      foreach ($list as $img) {
        if (!is_array($img)) continue;
        // prefer the "main" image field, not thumbs/webp
        $u = $img['image'] ?? $img['url'] ?? '';
        if (is_string($u) && $u !== '') $urls[] = $u;
      }
      return array_values(array_unique($urls));
    }

    // Case 3: fallback scan, but only keep full-size images (not thumbs)
    $this->walk($media, function ($v) use (&$urls) {
      if (!is_string($v)) return;
      if (strpos($v, 'http://') !== 0 && strpos($v, 'https://') !== 0) return;

      $path = strtolower(parse_url($v, PHP_URL_PATH) ?? '');

      // Skip common duplicates
      if (str_contains($path, '_thumb') || str_contains($path, 'square_thumb') || str_contains($path, 'thumb.webp')) return;
      if (str_contains($path, '/calendar/')) return;

      // Only accept likely image files
      if (!preg_match('#\.(jpg|jpeg|png|webp)$#', $path)) return;

      $urls[] = $v;
    });

    return array_values(array_unique($urls));
  }
  
	/**
	 * Extract a thumb-sized URL from the Loggia media response.
	 * get_media() is called with sizes=thumb so the album entries
	 * should contain tile-appropriate image URLs.
	 * Returns the first image URL from the album, or empty string.
	 */
	private function extract_thumb_url_from_media($media): string {
		if (!is_array($media)) return '';

		// Album structure (same as extract_media_urls checks)
		if (!empty($media['album']) && is_array($media['album'])) {
			$album = $media['album'];
			usort($album, fn($a, $b) => (int)($a['image_sort'] ?? 0) <=> (int)($b['image_sort'] ?? 0));

			foreach ($album as $img) {
				if (!is_array($img)) continue;
				$u = trim((string)($img['image'] ?? ''));
				if ($u !== '') return $u;
			}
		}

		// Other list shapes
		$list = $media['images'] ?? $media['items'] ?? $media['media'] ?? null;
		if (is_array($list)) {
			foreach ($list as $img) {
				if (!is_array($img)) continue;
				$u = trim((string)($img['image'] ?? $img['url'] ?? ''));
				if ($u !== '') return $u;
			}
		}

		return '';
	}

  private function extract_lat_lng($loc): array {
    // Best-effort: look for common keys anywhere in loc payload.
    $lat = null; $lng = null;

    $this->walk($loc, function ($v, $k = null) use (&$lat, &$lng) {
      if (!is_string($k)) return;

      $key = strtolower($k);
      if ($lat === null && in_array($key, ['lat','latitude'], true) && is_numeric($v)) $lat = $v;
      if ($lng === null && in_array($key, ['lng','lon','longitude','long'], true) && is_numeric($v)) $lng = $v;
    }, true);

    return [$lat, $lng];
  }

  // -----------------------------
  // Internals: WP upsert + helpers
  // -----------------------------

  private function upsert_post(string $property_id, string $title, string $content, string $excerpt): int {
    $existing = get_posts([
      'post_type'      => 'hcn_property',
      'post_status'    => 'any',
      'meta_key'       => 'loggia_property_id',
      'meta_value'     => $property_id,
      'posts_per_page' => 1,
      'fields'         => 'ids',
    ]);

    $postarr = [
      'post_type'    => 'hcn_property',
      'post_status'  => 'publish',
      'post_title'   => wp_strip_all_tags($title),
      'post_content' => $content,
      'post_excerpt' => $excerpt,
    ];

    if (!empty($existing)) {
      $postarr['ID'] = (int)$existing[0];
      $updated = wp_update_post($postarr, true);
      if (is_wp_error($updated)) {
        $this->log('Loggia importer: wp_update_post error: ' . $updated->get_error_message());
        $this->flush();
        return (int)$existing[0];
      }
      return (int)$updated;
    }

    $inserted = wp_insert_post($postarr, true);
    if (is_wp_error($inserted)) {
      $this->log('Loggia importer: wp_insert_post error: ' . $inserted->get_error_message());
      $this->flush();
      return 0;
    }
    return (int)$inserted;
  }

  private function save_payload_snapshot(int $post_id, string $meta_key, $payload): void {
    if (!is_array($payload)) return;
    $json = wp_json_encode($payload);
    if (!is_string($json)) return;
    // cap to ~30KB
    if (strlen($json) > 30000) $json = substr($json, 0, 30000);
    update_post_meta($post_id, $meta_key, $json);
  }

  private function safe_call(callable $fn, string $label) {
    try {
      return $fn();
    } catch (Throwable $e) {
      $this->log("Loggia importer ERROR: {$label} threw: " . $e->getMessage());
      $this->flush();
      return null;
    }
  }

  private function get_any($arr, array $keys) {
    if (!is_array($arr)) return null;
    foreach ($keys as $k) {
      if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
    }
    return null;
  }

  private function set_meta_unless_locked(int $post_id, string $key, $value): void {
    // Mirror Hostfully importer behavior if you use locking.
    // If you don't have locks in Loggia importer yet, this still works as a straight setter.
    update_post_meta($post_id, $key, $value);
  }

  /**
   * Walk an arbitrary nested array/object structure.
   * If $pass_key true, callback receives ($value, $key)
   */
  private function walk($node, callable $cb, bool $pass_key = false): void {
    if (is_array($node)) {
      foreach ($node as $k => $v) {
        if ($pass_key) $cb($v, $k); else $cb($v);
        $this->walk($v, $cb, $pass_key);
      }
    } elseif (is_object($node)) {
      foreach (get_object_vars($node) as $k => $v) {
        if ($pass_key) $cb($v, $k); else $cb($v);
        $this->walk($v, $cb, $pass_key);
      }
    }
  }

  private function log(string $msg): void {
    if ($this->logger && method_exists($this->logger, 'log')) {
      $this->logger->log($msg);
    }
  }

  private function flush(): void {
    if ($this->logger && method_exists($this->logger, 'save')) {
      $this->logger->save();
    }
  }
}