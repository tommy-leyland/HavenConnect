<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Loggia_Importer {
  private $logger;
  private $photos;

  public function __construct($logger, $photo_sync) {
    $this->logger = $logger;
    $this->photos = $photo_sync;
  }

  private function first(array $arr, array $keys, $default = null) {
    foreach ($keys as $k) {
      if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) return $arr[$k];
    }
    return $default;
  }

  public function list_property_ids(HavenConnect_Loggia_Client $client, string $page_id, string $locale = 'en') : array {
    $list = $client->list_properties_connections($page_id, $locale, 100, 0, 3, true);
    if (!is_array($list)) return [];

    $rows = $list['properties'] ?? [];
    if (!is_array($rows)) $rows = [];

    $ids = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $id = $row['property_id'] ?? $row['id'] ?? null;
      if ($id) $ids[] = (string)$id;
    }
    return $ids;
  }

  public function import_one(
    HavenConnect_Loggia_Client $client,
    string $property_id,
    string $page_id,
    string $locale
  ) : int {

    if ($this->logger) {
      $this->logger->log("Loggia importer: import_one() started. property_id={$property_id}, page_id={$page_id}, locale={$locale}");
      if ($this->logger) $this->logger->log("Loggia importer: CHECKPOINT A");
      $this->logger->save();
    }

    // Prefer the property's own page_id if we can fetch it from the connections list
 
    $connections = null;
    try {
      $connections = $client->list_properties_connections($page_id, $locale, 100, 0, 3, true);
    } catch (Throwable $e) {
      if ($this->logger) {
        $this->logger->log("Loggia importer ERROR: list_properties_connections threw: " . $e->getMessage());
        $this->logger->log($e->getTraceAsString());
        $this->logger->save();
      }
      // Continue without connections (we can still import via other endpoints)
      $connections = null;
    }

    if ($this->logger) $this->logger->log("Loggia importer: connections type=" . gettype($connections));
 
    if (is_array($connections) && !empty($connections['properties']) && is_array($connections['properties'])) {
      foreach ($connections['properties'] as $row) {
        if (!is_array($row)) continue;
        $rid = (string)($row['property_id'] ?? $row['id'] ?? '');
        if ($rid === (string)$property_id && !empty($row['page_id'])) {
          $page_id = (string)$row['page_id'];
          break;
        }
      }
    }

	// Get the property row from connections (gives reliable title + export url)
	$prop_row = null;
	if (is_array($connections) && !empty($connections['properties']) && is_array($connections['properties'])) {
	foreach ($connections['properties'] as $row) {
		if (!is_array($row)) continue;
		$rid = (string)($row['property_id'] ?? $row['id'] ?? '');
		if ($rid === (string)$property_id) {
		$prop_row = $row;
		break;
		}
	}
	}

    $summary = $client->get_summary($property_id, $page_id, $locale) ?? [];
    $desc    = $client->get_descriptions($property_id, $page_id, $locale) ?? [];
    $media   = $client->get_media($property_id, $page_id, $locale, 'thumb') ?? [];
    $loc     = $client->get_location($property_id, $page_id, $locale) ?? [];
	$title = 'Loggia Property ' . $property_id;
	$content_payload = $client->get_content($property_id, $page_id, $locale) ?? [];

  if ($this->logger) {
    $this->logger->log(
      "Loggia importer: types summary=" . gettype($summary) .
      " desc=" . gettype($desc) .
      " media=" . gettype($media) .
      " loc=" . gettype($loc) .
      " content=" . gettype($content_payload)
    );
    $this->logger->save();
  }

	if ($this->logger) {
	$this->logger->log('Loggia summary keys: ' . implode(',', array_keys((array)$summary)));
	$this->logger->log('Loggia desc keys: ' . implode(',', array_keys((array)$desc)));
	$this->logger->log('Loggia content keys: ' . implode(',', array_keys((array)$content_payload)));
	$this->logger->log('Loggia media keys: ' . implode(',', array_keys((array)$media)));
	$this->logger->log('Loggia loc keys: ' . implode(',', array_keys((array)$loc)));
	// small snippets (so log doesn’t explode)
	$this->logger->log('Loggia desc snippet: ' . substr(wp_json_encode($desc), 0, 600));
	$this->logger->log('Loggia content snippet: ' . substr(wp_json_encode($content_payload), 0, 600));
	}

  if ($this->logger) { $this->logger->save(); }

	$content = '';
	if (is_array($content_payload)) {
	$content =
		$content_payload['content'] ??
		$content_payload['html'] ??
		($content_payload['data']['content'] ?? null) ??
		($content_payload['data']['html'] ?? null) ??
		'';
	}
	$content = is_string($content) ? $content : '';

  if ($this->logger) {
  $this->logger->log('Loggia connections snippet: ' . substr(wp_json_encode($connections), 0, 600));
  $this->logger->log('Loggia content snippet: ' . substr(wp_json_encode($content_payload), 0, 600));
  $this->logger->log('Loggia media snippet: ' . substr(wp_json_encode($media), 0, 600));
  $this->logger->save();
}

	// Prefer connections list (this is what you saw in Postman: property_title/page_title)
	if (is_array($prop_row)) {
	$title = $prop_row['property_title'] ?? $prop_row['page_title'] ?? $title;

	// Optional but useful: store iCal export URL for availability later
	if (!empty($prop_row['cm_ical']['export_url'])) {
	}
	}

	// Fall back to summary only if it provides a non-empty title
	if (is_array($summary)) {
	$maybe = $summary['property_title'] ?? $summary['page_title'] ?? $summary['title'] ?? '';
	if (isset($summary['data']) && is_array($summary['data'])) {
		$maybe = $summary['data']['property_title'] ?? $summary['data']['page_title'] ?? $maybe;
	}
	if (is_string($maybe) && trim($maybe) !== '') {
		$title = $maybe;
	}
	}

	// --- Content: prefer /frontend/get/property/content ---
	$content = '';
	if (is_array($content_payload)) {
	$content =
		($content_payload['content'] ?? null) ??
		($content_payload['html'] ?? null) ??
		($content_payload['data']['content'] ?? null) ??
		($content_payload['data']['html'] ?? null) ??
		'';
	}
	$content = is_string($content) ? $content : '';

	// --- Excerpt: best-effort from descriptions endpoint (we’ll refine once we see shape) ---
	$excerpt = '';
	if (isset($desc['short']) && is_string($desc['short'])) {
	$excerpt = $desc['short'];
	} elseif (isset($desc['data']['short']) && is_string($desc['data']['short'])) {
	$excerpt = $desc['data']['short'];
	} elseif (isset($desc['data']['summary']) && is_string($desc['data']['summary'])) {
	$excerpt = $desc['data']['summary'];
	}

    // Upsert by meta key
    $post_id = $this->upsert_post($property_id, $title, $content, $excerpt);

	if (is_array($prop_row) && !empty($prop_row['cm_ical']['export_url'])) {
	update_post_meta($post_id, 'loggia_ical_export_url', $prop_row['cm_ical']['export_url']);
	}

    // Media URLs (best-effort until we see the response shape)
    $urls = $this->extract_media_urls($media);
    if (!empty($urls)) {
      update_post_meta($post_id, '_hcn_gallery_urls', array_values($urls));
      update_post_meta($post_id, '_hcn_featured_image_url', $urls[0]);

      // Your existing photo sync expects a payload — but we can keep it simple:
      // store URLs only for now (fast). Later we can route through Photo_Sync if needed.
      if ($this->logger) $this->logger->log("Loggia media: saved " . count($urls) . " URLs.");
    }

    // Store external IDs
    update_post_meta($post_id, 'hcn_source', 'loggia');
    update_post_meta($post_id, 'loggia_property_id', $property_id);

    // Location (best-effort; refine after live response)
    if (isset($loc['latitude'])) update_post_meta($post_id, 'latitude', (float)$loc['latitude']);
    if (isset($loc['longitude'])) update_post_meta($post_id, 'longitude', (float)$loc['longitude']);

    return (int)$post_id;
  }

  private function extract_media_urls($media) : array {
    if (!is_array($media)) return [];

    // Common patterns: media['images'] or media['data'] etc.
    $items =
	$media['images'] ??
	$media['photos'] ??
	$media['media'] ??
	$media['data'] ??
	$media['items'] ??
	[];
    if (!is_array($items)) $items = [];

    $urls = [];
    foreach ($items as $img) {
      if (!is_array($img)) continue;
		$url =
		$img['url'] ??
		$img['src'] ??
		$img['image'] ??
		$img['path'] ??
		$img['thumb'] ??
		($img['sizes']['thumb'] ?? null) ??
		($img['sizes']['square_thumb'] ?? null);

      if ($url && is_string($url)) $urls[] = $url;
    }

    // de-dupe
    $urls = array_values(array_unique(array_filter($urls)));
    return $urls;
  }

  private function upsert_post(string $property_id, string $title, string $content, string $excerpt) : int {
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
      return (int)wp_update_post($postarr, true);
    }

    return (int)wp_insert_post($postarr, true);
  }
}