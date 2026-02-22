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
      $qs = '';
      if (!empty($params)) {
        $qs = '?' . http_build_query($params);
      }

      $final = $url . $qs;

      $args = [
        'timeout' => 20,
        'headers' => $headers,
      ];

      $res = wp_remote_get($final, $args);

      if (is_wp_error($res)) {
        if ($this->logger) $this->logger->log("API error: " . $res->get_error_message());
        continue;
      }

      $code = wp_remote_retrieve_response_code($res);
      if ($code < 200 || $code >= 300) {
        if ($this->logger) $this->logger->log("API HTTP {$code} for {$final} resp=" . substr((string)wp_remote_retrieve_body($res), 0, 500));
        continue;
      }

      $body = wp_remote_retrieve_body($res);
      if (!$body) return null;

      return json_decode($body, true);
    }

    return null;
  }

  /** Shared POST wrapper (JSON). */
  public function request_post(array $endpoints, array $body = []) {
    foreach ($endpoints as $url => $headers) {
      $args = [
        'timeout' => 20,
        'headers' => array_merge($headers, [
          'Content-Type' => 'application/json',
          'Accept'       => 'application/json',
        ]),
        'body' => wp_json_encode($body),
      ];

      $res = wp_remote_post($url, $args);

      if (is_wp_error($res)) {
        if ($this->logger) $this->logger->log("API POST error: " . $res->get_error_message());
        continue;
      }

      $code = wp_remote_retrieve_response_code($res);
      $resp = wp_remote_retrieve_body($res);

      if ($code < 200 || $code >= 300) {
        error_log("HCN QUOTE HTTP {$code} url={$url} resp=" . substr((string)$resp, 0, 1200));
        if ($this->logger) {
          $this->logger->log("API POST HTTP {$code} for {$url} body=" . wp_json_encode($body) . " resp=" . substr((string)$resp, 0, 800));
        }
        continue;
      }

      if (!$resp) return null;
      return json_decode($resp, true);
    }

    return null;
  }

  /** Featured properties (Hostfully v3.2) */
  public function get_featured_list(string $apiKey, string $agencyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    $parsed = $this->request($endpoints);
    if (!is_array($parsed) || empty($parsed['featuredProperties'])) return [];
    return $parsed['featuredProperties'];
  }

  /** All properties for agency (v3.2) with cursor pagination */
  public function get_properties_by_agency(string $apiKey, string $agencyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/properties" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://api.hostfully.com/api/v3.2/properties"      => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    $all = [];
    $cursor = null;
    $limit  = 100;

    for ($guard = 0; $guard < 50; $guard++) {
      $params = [
        'agencyUid' => $agencyUid,
        '_limit'    => $limit,
      ];
      if ($cursor) $params['_cursor'] = $cursor;

      $parsed = $this->request($endpoints, $params);

      $props = [];
      if (is_array($parsed) && !empty($parsed['properties']) && is_array($parsed['properties'])) {
        $props = $parsed['properties'];
        $all   = array_merge($all, $props);
      } else {
        break;
      }

      $next =
        $parsed['_metadata']['nextCursor'] ??
        $parsed['_metadata']['next_cursor'] ??
        $parsed['nextCursor'] ??
        $parsed['next_cursor'] ??
        null;

      if (!$next) break;
      if ($next === $cursor) break;

      $cursor = $next;

      if (count($props) < $limit) break;
    }

    if ($this->logger) $this->logger->log("Properties: fetched " . count($all) . " for agency {$agencyUid}");
    return $all;
  }

  /** Get tags for a property. */
  public function get_property_tags(string $apiKey, string $propertyUid): array {
    $endpoints_v32 = [
      "https://platform.hostfully.com/api/v3.2/tags" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/tags"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints_v32, [
      'objectUid'  => $propertyUid,
      'objectType' => 'PROPERTY',
    ]);
    $asList = $this->normalize_tags_payload($parsed);
    if (!empty($asList)) return $asList;

    $endpoints_v3 = [
      "https://platform.hostfully.com/api/v3/tags" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3/tags"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints_v3, [
      'objectUid'  => $propertyUid,
      'objectType' => 'PROPERTY',
    ]);

    return $this->normalize_tags_payload($parsed);
  }

  /** Normalize various tag payload shapes into a flat array of strings. */
  private function normalize_tags_payload($parsed): array {
    if (!is_array($parsed) || empty($parsed)) return [];

    $val = isset($parsed['tags']) ? $parsed['tags'] : $parsed;

    $out = [];
    $walk = function($v) use (&$out, &$walk) {
      if (is_string($v)) { $out[] = trim($v); return; }
      if (is_array($v)) {
        if (isset($v['name']) && is_string($v['name'])) { $out[] = trim($v['name']); return; }
        foreach ($v as $vv) $walk($vv);
      }
    };
    $walk($val);

    return array_values(array_unique(array_filter($out)));
  }

  /** Property calendar — Hostfully v3.2 */
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
    if (!is_array($entries)) return [];

    if ($this->logger) $this->logger->log("Calendar API returned " . count($entries) . " entries ({$from}→{$to}) for {$propertyUid}");
    return $entries;
  }

  /** Photos — Hostfully v3.2 */
  public function get_property_photos(string $apiKey, string $propertyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/photos" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/photos"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    $parsed = $this->request($endpoints, ['propertyUid' => $propertyUid]);
    if (!is_array($parsed) || empty($parsed['photos'])) {
      if ($this->logger) $this->logger->log("Photos API returned empty for {$propertyUid}");
      return [];
    }

    if ($this->logger) $this->logger->log("Photos API returned " . count($parsed['photos']) . " photos for {$propertyUid}");
    return $parsed['photos'];
  }

  /** Property details (v3.2) */
  public function get_property_details(string $apiKey, string $propertyUid) {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/properties/{$propertyUid}" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/properties/{$propertyUid}"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    return $this->request($endpoints);
  }

  /** Property descriptions (v3.2) */
  public function get_property_descriptions(string $apiKey, string $propertyUid): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/property-descriptions" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/property-descriptions"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    $parsed = $this->request($endpoints, ['propertyUid' => $propertyUid]);
    return is_array($parsed) ? $parsed : [];
  }

  /** Amenities (v3.2) */
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
   *
   * The docs confirm the endpoint + purpose, but the interactive page doesn’t expose a static schema,
   * so we keep payload minimal and log any 4xx/5xx in request_post().
   */
  public function calculate_quote(string $apiKey, string $propertyUid, string $checkin, string $checkout, int $guests = 0): array {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/quotes" => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];

    // Minimal payload that commonly works:
    $payload = [
      'propertyUid' => $propertyUid,
      'checkIn'     => $checkin,
      'checkOut'    => $checkout,
    ];
    if ($guests > 0) $payload['guests'] = $guests;

    $parsed = $this->request_post($endpoints, $payload);
    return is_array($parsed) ? $parsed : [];
  }
}