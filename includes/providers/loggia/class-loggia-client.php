<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Loggia_Client {
  private string $base_url;
  private string $api_key;
  private $logger;

  public function __construct(string $base_url, string $api_key, $logger = null) {
    $this->base_url = rtrim($base_url, '/');
    $this->api_key  = $api_key;
    $this->logger   = $logger;
  }

  private function post_json(string $path, array $body) : ?array {
    if (!$this->base_url || !$this->api_key) return null;

    $url = $this->base_url . $path;

    $res = wp_remote_post($url, [
      'timeout' => 8,
      'headers' => [
        'x-api-key'    => $this->api_key,
        'accept'       => 'application/json',
        'content-type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($res)) {
      if ($this->logger) $this->logger->log("Loggia POST error: " . $res->get_error_message());
      return null;
    }

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
      if ($this->logger) $this->logger->log("Loggia HTTP {$code} url={$url} resp=" . substr((string)$raw, 0, 800));
      return null;
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
      if ($this->logger) $this->logger->log("Loggia JSON decode failed url={$url} resp=" . substr((string)$raw, 0, 300));
      return null;
    }

    return $json;
  }

  public function external_api(string $action) : ?array {
    return $this->post_json('/api/admin/lodge/external-api', [
      'action' => $action,
    ]);
  }

  // ----- Endpoints (matching your working Postman collection) -----

  public function list_properties_connections(string $page_id, string $locale = 'en', int $limit = 100, int $offset = 0, int $statuses = 3, bool $includeNotAvailable = true) : ?array {
    $action =
      "/api/lodge/properties/list/data/connections"
      . "?page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale)
      . "&limit=" . (int)$limit
      . "&offset=" . (int)$offset
      . "&statuses=" . (int)$statuses
      . "&includeNotAvailable=" . ($includeNotAvailable ? 'true' : 'false')
      . "&data_type=list";

    return $this->external_api($action);
  }

  public function get_summary(string $property_id, string $page_id, string $locale) : ?array {
    $action = "/api/lodge/properties/summary?property_id=" . rawurlencode($property_id)
      . "&page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale);
    return $this->external_api($action);
  }

  public function get_content(string $property_id, string $page_id, string $locale) : ?array {
    $action = "/api/lodge/frontend/get/property/content/" . rawurlencode($property_id)
      . "?page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale);
    return $this->external_api($action);
  }

  public function get_descriptions(string $property_id, string $page_id, string $locale) : ?array {
    $action = "/api/lodge/properties/descriptions?property_id=" . rawurlencode($property_id)
      . "&page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale);
    return $this->external_api($action);
  }

  public function get_media(string $property_id, string $page_id, string $locale, string $sizes = 'thumb', int $all_images = 1) : ?array {
    $action = "/api/lodge/properties/media?property_id=" . rawurlencode($property_id)
      . "&page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale)
      . "&sizes=" . rawurlencode($sizes)
      . "&all_images=" . (int)$all_images;
    return $this->external_api($action);
  }

  public function get_features_by_group(string $property_id, string $page_id, string $locale) : ?array {
    $action = "/api/lodge/properties/features-by-group/all?property_id=" . rawurlencode($property_id)
      . "&page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale);
    return $this->external_api($action);
  }

  public function get_location(string $property_id, string $page_id, string $locale) : ?array {
    // Using the action you validated in Postman (even if it's builder-based)
    $action = "/api/builder/properties/manager/property/location/get?property_id=" . rawurlencode($property_id)
      . "&page_id=" . rawurlencode($page_id)
      . "&locale=" . rawurlencode($locale)
      . "&parent=";
    return $this->external_api($action);
  }
}