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
  
  public function get_property_tags(string $apiKey, string $propertyUid): array {
	  $endpoints = [
		"https://platform.hostfully.com/api/v3.2/tags" => ["X-HOSTFULLY-APIKEY" => $apiKey],
		"https://sandbox.hostfully.com/api/v3.2/tags"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
	  ];

	  // Hostfully Tags API expects objectUid + objectType
	  $parsed = $this->request($endpoints, [
		'objectUid'  => $propertyUid,
		'objectType' => 'PROPERTY',
	  ]);

	  if (!is_array($parsed)) return [];

	  // Depending on contract, tags may be nested or returned directly
	  if (isset($parsed['tags']) && is_array($parsed['tags'])) return $parsed['tags'];

	  return $parsed;
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
   * Get property DBS settings (Direct Booking System config)
   * GET https://{host}/api/v3.2/dbs/properties/{propertyUid}
   *
   * Docs: https://dev.hostfully.com/reference/getpropertydbssettings
   */
  public function get_property_dbs_settings(string $apiKey, string $propertyUid) {
    $endpoints = [
      "https://platform.hostfully.com/api/v3.2/dbs/properties/{$propertyUid}" => ["X-HOSTFULLY-APIKEY" => $apiKey],
      "https://sandbox.hostfully.com/api/v3.2/dbs/properties/{$propertyUid}"  => ["X-HOSTFULLY-APIKEY" => $apiKey],
    ];
    return $this->request($endpoints);
  }

  /**
   * Calculate quote (v3.2)
   * POST https://platform.hostfully.com/api/v3.2/quotes
   */
  public function calculate_quote(string $apiKey, string $agencyUid, string $propertyUid, string $checkin, string $checkout, int $guests = 0): array {
    $url = "https://platform.hostfully.com/api/v3.2/quotes";

    $payload = [
      'agencyUid'    => $agencyUid,
      'propertyUid'  => $propertyUid,
      'checkInDate'  => $checkin,
      'checkOutDate' => $checkout,
    ];

    if ($guests > 0) {
      $payload['guests'] = $guests;
    }

    error_log('[HCN calculate_quote] POST ' . $url . ' payload: ' . wp_json_encode($payload));

    $args = [
      'timeout' => 20,
      'headers' => [
        'X-HOSTFULLY-APIKEY' => $apiKey,
        'Content-Type'       => 'application/json',
        'Accept'             => 'application/json',
      ],
      'body' => wp_json_encode($payload),
    ];

    $res = wp_remote_post($url, $args);

    if (is_wp_error($res)) {
      error_log('[HCN calculate_quote] WP_Error: ' . $res->get_error_message());
      return [];
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    error_log('[HCN calculate_quote] HTTP ' . $code . ' response: ' . substr($body, 0, 2000));

    if ($code < 200 || $code >= 300) {
      return [];
    }

    $parsed = json_decode($body, true);
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * Create a lead (booking) in Hostfully
   */
  public function create_lead(string $apiKey, array $payload): array {
    $url  = 'https://platform.hostfully.com/api/v3.2/leads';

    // Force raw JSON request body (WP can otherwise form-encode arrays depending on transport)
    $body = wp_json_encode($payload);

    $args = [
      'method'      => 'POST',
      'timeout'     => 20,
      'redirection' => 5,
      'blocking'    => true,
      'data_format' => 'body',
      'headers'     => [
        'X-HOSTFULLY-APIKEY' => $apiKey,
        'Content-Type'       => 'application/json; charset=utf-8',
        'Accept'             => '*/*',
      ],
      'body'        => $body,
    ];

    error_log('[HCN create_lead] payload: ' . $body);
    $res  = wp_remote_request($url, $args);

    if (is_wp_error($res)) {
      error_log('[HCN create_lead] WP_Error: ' . $res->get_error_message());
      return [];
    }

    $code = wp_remote_retrieve_response_code($res);
    $resp_body = wp_remote_retrieve_body($res);

    error_log('[HCN create_lead] HTTP ' . $code . ' response: ' . substr((string)$resp_body, 0, 2000));

    if ($code < 200 || $code >= 300) return [];
    $parsed = json_decode((string)$resp_body, true);
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * Calculate quote with a promo code applied
   */
  public function calculate_quote_with_promo(string $apiKey, string $agencyUid, string $propertyUid, string $checkin, string $checkout, int $guests, string $promoCode): array {
    $url     = 'https://platform.hostfully.com/api/v3.2/quotes';
    $payload = [
      'agencyUid'    => $agencyUid,
      'propertyUid'  => $propertyUid,
      'checkInDate'  => $checkin,
      'checkOutDate' => $checkout,
      'promoCode'    => $promoCode,
    ];
    if ($guests > 0) $payload['guests'] = $guests;
    $args = [
      'timeout' => 20,
      'headers' => [
        'X-HOSTFULLY-APIKEY' => $apiKey,
        'Content-Type'       => 'application/json',
        'Accept'             => 'application/json',
      ],
      'body' => wp_json_encode($payload),
    ];
    $res  = wp_remote_post($url, $args);
    if (is_wp_error($res)) return [];
    $code = wp_remote_retrieve_response_code($res); 
    $body = wp_remote_retrieve_body($res);
    error_log('[HCN quote_promo] HTTP ' . $code . ' response: ' . substr($body, 0, 1000));
    if ($code < 200 || $code >= 300) return [];
    $parsed = json_decode($body, true);
    return is_array($parsed) ? $parsed : [];
  }
}