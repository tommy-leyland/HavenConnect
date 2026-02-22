<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Api_Client {

  private $logger;

  public function __construct($logger = null) {
    $this->logger = $logger;
  }

  /** Shared GET wrapper. */
  public function request(array $endpoints, array $params = []) {
    foreach ($endpoints as $url => $headers) {
      $qs = !empty($params) ? ('?' . http_build_query($params)) : '';
      $final = $url . $qs;

      $args = [
        'timeout' => 20,
        'headers' => $headers,
      ];

      $res = wp_remote_get($final, $args);

      if (is_wp_error($res)) {
        if ($this->logger) $this->logger->log("API GET error: " . $res->get_error_message());
        continue;
      }

      $code = wp_remote_retrieve_response_code($res);
      $body = wp_remote_retrieve_body($res);

      if ($code < 200 || $code >= 300) {
        // ✅ Correct: $code/$body exist here
        error_log("HCN GET HTTP {$code} url={$final} resp=" . substr((string)$body, 0, 1200));
        if ($this->logger) $this->logger->log("API GET HTTP {$code} for {$final} resp=" . substr((string)$body, 0, 800));
        continue;
      }

      if (!$body) return null;
      return json_decode($body, true);
    }

    return null;
  }

  /** Shared POST wrapper (JSON). */
  public function request_post(array $endpoints, array $payload = []) {
    foreach ($endpoints as $url => $headers) {
      $args = [
        'timeout' => 20,
        'headers' => array_merge($headers, [
          'Content-Type' => 'application/json',
          'Accept'       => 'application/json',
        ]),
        'body' => wp_json_encode($payload),
      ];

      $res = wp_remote_post($url, $args);

      if (is_wp_error($res)) {
        if ($this->logger) $this->logger->log("API POST error: " . $res->get_error_message());
        continue;
      }

      $code = wp_remote_retrieve_response_code($res);
      $body = wp_remote_retrieve_body($res);

      if ($code < 200 || $code >= 300) {
        // ✅ Correct: $code/$body exist here
        error_log("HCN QUOTE HTTP {$code} url={$url} resp=" . substr((string)$body, 0, 1200));
        if ($this->logger) $this->logger->log("API POST HTTP {$code} for {$url} payload=" . wp_json_encode($payload) . " resp=" . substr((string)$body, 0, 800));
        continue;
      }

      if (!$body) return null;
      return json_decode($body, true);
    }

    return null;
  }

  // --- Existing GET methods from your current file (kept) ---
  public function get_featured_list(string $apiKey, string $agencyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints);
    if (!is_array($parsed) || empty($parsed['featuredProperties'])) return [];
    return $parsed['featuredProperties'];
  }

  public function get_properties_by_agency(string $apiKey, string $agencyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/properties" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://api.hostfully.com/api/v3.2/properties"      => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    // cursor pagination (you said this is working now)
    $all = [];
    $cursor = null;
    $limit = 100;

    for ($guard = 0; $guard < 50; $guard++) {
      $params = ['agencyUid' => $agencyUid, '_limit' => $limit];
      if ($cursor) $params['_cursor'] = $cursor;

      $parsed = $this->request($endpoints, $params);
      $props = (is_array($parsed) && !empty($parsed['properties']) && is_array($parsed['properties']))
        ? $parsed['properties'] : [];

      if (!$props) break;
      $all = array_merge($all, $props);

      $next =
        $parsed['_metadata']['nextCursor'] ??
        $parsed['_metadata']['next_cursor'] ??
        $parsed['nextCursor'] ??
        $parsed['next_cursor'] ??
        null;

      if (!$next || $next === $cursor) break;
      $cursor = $next;

      if (count($props) < $limit) break;
    }

    return $all;
  }

  public function get_property_calendar(string $apiKey, string $propertyUid, string $from, string $to): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/property-calendar/{$propertyUid}" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/property-calendar/{$propertyUid}"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints, ['from' => $from, 'to' => $to]);
    if (!is_array($parsed)) return [];
    $cal = $parsed['calendar'] ?? null;
    if (!is_array($cal)) return [];
    $entries = $cal['entries'] ?? [];
    return is_array($entries) ? $entries : [];
  }

  public function get_property_photos(string $apiKey, string $propertyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/photos" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/photos"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints, ['propertyUid' => $propertyUid]);
    if (!is_array($parsed) || empty($parsed['photos'])) return [];
    return $parsed['photos'];
  }

  public function get_property_details(string $apiKey, string $propertyUid) {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/properties/{$propertyUid}" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/properties/{$propertyUid}"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    return $this->request($endpoints);
  }

  public function get_property_descriptions(string $apiKey, string $propertyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/property-descriptions" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/property-descriptions"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints, ['propertyUid' => $propertyUid]);
    return is_array($parsed) ? $parsed : [];
  }

  public function get_property_amenities(string $apiKey, string $propertyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/amenities" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/amenities"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints, ['propertyUid' => $propertyUid]);
    if (!is_array($parsed) || empty($parsed['amenities'])) return [];
    return $parsed['amenities'];
  }

  /**
   * Calculate quote (v3.2)
   * POST https://platform.hostfully.com/api/v3.2/quotes
   */
  public function calculate_quote(string $apiKey, string $agencyUid, string $propertyUid, string $checkin, string $checkout, int $guests = 0): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/quotes" => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    $payload = [
      'agencyUid'   => $agencyUid,
      'propertyUid' => $propertyUid,
      'checkIn'     => $checkin,
      'checkOut'    => $checkout,
    ];

    if ($guests > 0) {
      $payload['guests'] = $guests;
    }

    $parsed = $this->request_post($endpoints, $payload);
    return is_array($parsed) ? $parsed : [];
  }
}