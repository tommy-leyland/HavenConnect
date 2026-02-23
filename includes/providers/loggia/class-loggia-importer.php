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

    // Prefer the property's own page_id if we can fetch it from the connections list
    $connections = $client->list_properties_connections($page_id, $locale, 100, 0, 3, true);
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

    $summary = $client->get_summary($property_id, $page_id, $locale) ?? [];
    $desc    = $client->get_descriptions($property_id, $page_id, $locale) ?? [];
    $media   = $client->get_media($property_id, $page_id, $locale, 'thumb') ?? [];
    $loc     = $client->get_location($property_id, $page_id, $locale) ?? [];

    // Title guess: adjust once we see live payload
    $title = 'Loggia Property ' . $property_id;
    if (is_array($summary)) {
      $title = $this->first($summary, ['name','title','property_name'], $title);
      if (isset($summary['data']) && is_array($summary['data'])) {
        $title = $this->first($summary['data'], ['name','title','property_name'], $title);
      }
    }

    $content = '';
    $excerpt = '';

    // Description guess: adjust once we see live payload
    if (isset($desc['long']) || isset($desc['short'])) {
      $content = (string)($desc['long'] ?? '');
      $excerpt = (string)($desc['short'] ?? '');
    } elseif (isset($desc['data']) && is_array($desc['data'])) {
      $content = (string)($desc['data']['long'] ?? $desc['data']['description'] ?? '');
      $excerpt = (string)($desc['data']['short'] ?? $desc['data']['summary'] ?? '');
    }

    // Upsert by meta key
    $post_id = $this->upsert_post($property_id, $title, $content, $excerpt);

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
    $items = $media['images'] ?? $media['data'] ?? $media['items'] ?? [];
    if (!is_array($items)) $items = [];

    $urls = [];
    foreach ($items as $img) {
      if (!is_array($img)) continue;
      $url =
        $img['url'] ??
        $img['src'] ??
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