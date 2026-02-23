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

  private function get(string $path, array $query = []) : ?array {
    if (!$this->base_url || !$this->api_key) return null;

    $url = $this->base_url . $path;
    if (!empty($query)) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);

    $res = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'x-api-key' => $this->api_key,
        'accept'    => 'application/json',
      ],
    ]);

    if (is_wp_error($res)) {
      if ($this->logger) $this->logger->log("Loggia GET error: " . $res->get_error_message());
      return null;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
      if ($this->logger) $this->logger->log("Loggia HTTP {$code} url={$url} resp=" . substr((string)$body, 0, 800));
      return null;
    }

    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
  }

  public function list_properties(string $page_id) : ?array {
    return $this->get('/api/builder/properties/manager/list/data/v2', ['page_id' => $page_id]);
  }

  public function get_summary(string $property_id, string $page_id, string $locale) : ?array {
    return $this->get('/api/lodge/properties/summary', [
      'property_id' => $property_id,
      'page_id'     => $page_id,
      'locale'      => $locale,
    ]);
  }

  public function get_descriptions(string $property_id, string $page_id, string $locale) : ?array {
    return $this->get('/api/lodge/properties/descriptions', [
      'property_id' => $property_id,
      'page_id'     => $page_id,
      'locale'      => $locale,
    ]);
  }

  public function get_media(string $property_id, string $page_id, string $locale, string $sizes='thumb') : ?array {
    return $this->get('/api/lodge/properties/media', [
      'property_id' => $property_id,
      'page_id'     => $page_id,
      'locale'      => $locale,
      'sizes'       => $sizes,
      'all_images'  => 1,
    ]);
  }

  public function get_location(string $property_id, string $page_id, string $locale) : ?array {
    return $this->get('/api/builder/properties/manager/property/location/get', [
      'property_id' => $property_id,
      'page_id'     => $page_id,
      'locale'      => $locale,
      'parent'      => '',
    ]);
  }
}