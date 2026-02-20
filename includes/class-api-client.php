<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Api_Client
 *
 * Fetches:
 *  - Featured properties
 *  - Tags
 *  - Photos
 *  - Calendar (availability + daily pricing/rules)
 *
 * Uses JSON ONLY (forces "Accept: application/json" and decodes associative arrays).
 */
class HavenConnect_Api_Client {

    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Universal HTTP GET with production + sandbox failover.
     * $endpoints: [ 'https://.../path' => [ 'Header-Name' => 'value' ], ... ]
     * $params:    key/value pairs to append to the URL as a query string.
     */
    private function request(array $endpoints, array $params = [], int $timeout = 30): ?array {

        foreach ($endpoints as $base => $headers) {

            // Build URL with params
            $url  = add_query_arg($params, $base);

            // Force JSON
            $args = [
                'headers' => array_merge((array)$headers, [
                    'Accept' => 'application/json',
                ]),
                'timeout' => $timeout,
            ];

            $res  = wp_remote_get($url, $args);

            if (is_wp_error($res)) {
                $this->logger->log("HTTP error on $base: " . $res->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);

            if ($code === 401) {
                $this->logger->log("401 Unauthorized from $base – trying fallback.");
                continue;
            }

            if ($code < 200 || $code >= 300) {
                $this->logger->log("Non-2xx ($code): $url");
                continue;
            }

            $decoded = json_decode($body, true);

            // Trace (truncated)
            $this->logger->log("DEBUG decoded JSON: " . substr(print_r($decoded, true), 0, 1000));

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Fetch Featured properties (Hostfully v3.2).
     * We filter by agencyUid and tag=Featured; limit to 10 for the importer.
     */
    public function get_featured_list(string $apiKey, string $agencyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/properties" => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
            "https://sandbox.hostfully.com/api/v3.2/properties" => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
        ];

        $params = [
            'agencyUid' => $agencyUid,
            'tags'      => 'Featured',
            '_limit'    => 10,
        ];

        $parsed = $this->request($endpoints, $params, 45);

        if (!$parsed) return [];

        if (isset($parsed['properties']) && is_array($parsed['properties'])) {
            return $parsed['properties'];
        }

        return [];
    }

    /**
     * Fetch tags for a property (Hostfully v3.2 /tags endpoint with filters).
     */
    public function get_property_tags(string $apiKey, string $propertyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/tags" => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
            "https://sandbox.hostfully.com/api/v3.2/tags" => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
        ];

        $params = [
            'objectUid'  => $propertyUid,
            'objectType' => 'PROPERTY',
        ];

        $parsed = $this->request($endpoints, $params);

        if (!$parsed) return [];

        if (isset($parsed['tagsForObject']['tags']) && is_array($parsed['tagsForObject']['tags'])) {
            return $parsed['tagsForObject']['tags'];
        }

        return [];
    }

    /**
     * Fetch photos for a property (Hostfully v3.2).
     * Returns array of photo items; each contains originalImageUrl / largeScaleImageUrl / etc.
     */
    public function get_property_photos(string $apiKey, string $propertyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/photos" => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
            "https://sandbox.hostfully.com/api/v3.2/photos"  => [
                'X-HOSTFULLY-APIKEY' => $apiKey,
            ],
        ];

        $params = [
            'propertyUid' => $propertyUid,
        ];

        $parsed = $this->request($endpoints, $params);

        $this->logger->log("DEBUG photos raw: " . substr(print_r($parsed, true), 0, 1000));

        if (!$parsed) return [];

        if (isset($parsed['photos']) && is_array($parsed['photos'])) {
            return $parsed['photos'];
        }

        return [];
    }

    /* PROPERTY DETAILS */
    public function get_property_details(string $apiKey, string $propertyUid) {
        $endpoints = [
            "https://platform.hostfully.com/api/v3/properties/{$propertyUid}" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ]
        ];
        return $this->request($endpoints);
    }

    /* Ameneties */
    public function get_available_amenities(string $apiKey): array
    {
        $endpoints = [
            "https://platform.hostfully.com/api/v3/available-amenities" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ]
        ];

        $parsed = $this->request($endpoints);

        if (!is_array($parsed)) return [];

        return $parsed;
    }

    /**
     * Fetch daily calendar (availability + pricing + rules) for a property (Hostfully v3.2).
     * Expected response: { "calendar": [ { "date": "YYYY-MM-DD", "available": true, "price": 123.45, "minStay": 2, "maxStay": 0, "closedToArrival": false, "closedToDeparture": false }, ... ] }
     */
	public function get_property_calendar(string $apiKey, string $property_uid): array
	{
		$endpoints = [
			"https://platform.hostfully.com/api/v3/property-calendar/{$property_uid}" => [
				'X-HOSTFULLY-APIKEY' => $apiKey,
			],
		];

		// ZERO query params — required by Hostfully API
		$params = [];

		$parsed = $this->request($endpoints, $params);

		if (!$parsed || !is_array($parsed)) {
			$this->logger->log("Calendar API returned invalid response for $property_uid");
			return ['calendar' => []];
		}

		$this->logger->log("DEBUG Calendar Raw for $property_uid: " . substr(print_r($parsed, true), 0, 2000));

		// If Hostfully returns array directly, wrap it.
		if (array_is_list($parsed)) {
			return ['calendar' => $parsed];
		}

		// If Hostfully returns "calendar" => [...]
		if (isset($parsed['calendar'])) {
			return ['calendar' => $parsed['calendar']];
		}

		return ['calendar' => []];
	}


	

}