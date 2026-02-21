<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Api_Client
 *
 * Clean API client for:
 *  - Featured properties (v3.2)
 *  - All properties (v3.2)
 *  - Tags (v3)
 *  - Photos (v3.2 with v3 fallback)
 *  - Property Details
 *  - Amenities (v3.2)
 */
class HavenConnect_Api_Client {

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /**
     * Shared GET wrapper.
     */
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
                if ($this->logger) $this->logger->log("API HTTP $code for $final");
                continue;
            }

            $body = wp_remote_retrieve_body($res);
            if (!$body) return null;

            return json_decode($body, true);
        }

        return null;
    }

    /**
     * Featured properties (Hostfully v3.2)
     */
    public function get_featured_list(string $apiKey, string $agencyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3.2/agencies/{$agencyUid}/featured-properties" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $parsed = $this->request($endpoints);

        if (!is_array($parsed) || empty($parsed['featuredProperties'])) {
            return [];
        }

        return $parsed['featuredProperties'];
    }

    /**
     * Fallback: All properties for agency (v3.2)
     */
    public function get_properties_by_agency(string $apiKey, string $agencyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/properties" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://api.hostfully.com/api/v3.2/properties" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $params = ['agencyUid' => $agencyUid];

        $parsed = $this->request($endpoints, $params);

        if (!is_array($parsed) || empty($parsed['properties'])) {
            return [];
        }

        return $parsed['properties'];
    }

    /**
    * Get tags for a property.
    *
    * Primary: v3.2 /tags?propertyUid={uid}
    * Fallback: v3  /properties/{uid}/tags
    *
    * Returns a flat array of strings (tag names), regardless of upstream shape.
    */
    public function get_property_tags(string $apiKey, string $propertyUid): array {

	// Hostfully v3.2: Get tags for object
	// GET /api/v3.2/tags?objectUid={uid}&objectType=PROPERTY
	$endpoints_v32 = [
		"https://platform.hostfully.com/api/v3.2/tags" => [
		"X-HOSTFULLY-APIKEY" => $apiKey
		],
		"https://sandbox.hostfully.com/api/v3.2/tags" => [
		"X-HOSTFULLY-APIKEY" => $apiKey
		],
	];

	$parsed = $this->request($endpoints_v32, [
		'objectUid'  => $propertyUid,
		'objectType' => 'PROPERTY',
	]);

	$asList = $this->normalize_tags_payload($parsed);
	if (!empty($asList)) {
		return $asList;
	}

	// Fallback: Hostfully v3 also uses /api/v3/tags with objectUid/objectType
	$endpoints_v3 = [
		"https://platform.hostfully.com/api/v3/tags" => [
		"X-HOSTFULLY-APIKEY" => $apiKey
		],
		"https://sandbox.hostfully.com/api/v3/tags" => [
		"X-HOSTFULLY-APIKEY" => $apiKey
		],
	];

	$parsed = $this->request($endpoints_v3, [
		'objectUid'  => $propertyUid,
		'objectType' => 'PROPERTY',
	]);

	return $this->normalize_tags_payload($parsed);
	}

    

    /**
    * Normalize various Hostfully tag payload shapes into a flat array of strings.
    * Accepts:
    *   { tags: ["foo","bar"] }
    *   { tags: [{name:"foo"}, {name:"bar"}] }
    *   ["foo","bar"]
    *   [{name:"foo"}]
    */
    private function normalize_tags_payload($parsed): array {
        if (!is_array($parsed) || empty($parsed)) return [];

        // If it's wrapped { tags: ... }
        if (isset($parsed['tags'])) {
            $val = $parsed['tags'];
        } else {
            $val = $parsed;
        }

        $out = [];
        $walk = function($v) use (&$out, &$walk) {
            if (is_string($v)) { $out[] = trim($v); return; }
            if (is_array($v)) {
                if (isset($v['name']) && is_string($v['name'])) {
                    $out[] = trim($v['name']);
                    return;
                }
                foreach ($v as $vv) $walk($vv);
            }
        };
        $walk($val);

        return array_values(array_unique(array_filter($out)));
    }

    /**
    * Property calendar (availability + pricing) — Hostfully v3.2
    * GET /api/v3.2/property-calendar/{propertyUid}?from=YYYY-MM-DD&to=YYYY-MM-DD
    */
    public function get_property_calendar(string $apiKey, string $propertyUid, string $from, string $to): array
    {
        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/property-calendar/{$propertyUid}" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3.2/property-calendar/{$propertyUid}" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $params = ['from' => $from, 'to' => $to];

        $parsed = $this->request($endpoints, $params);

        // Expected: { "calendar": { "entries": [ ... ], "propertyUid": "..." } }
        if (!is_array($parsed)) return [];
        $cal = $parsed['calendar'] ?? null;
        if (!is_array($cal)) return [];

        $entries = $cal['entries'] ?? [];
        if (!is_array($entries)) return [];

        if ($this->logger) {
            $this->logger->log("Calendar API returned " . count($entries) . " entries ({$from}→{$to}) for {$propertyUid}");
        }

        return $entries;
    }

    /**
    * Fetch property photos (REAL endpoint)
    *
    * Hostfully returns ALL photos under:
    *   /api/v3.2/photos?propertyUid={UID}
    *
    * Not under /properties/{uid}/photos.
    */
    public function get_property_photos(string $apiKey, string $propertyUid): array {

        // Correct Hostfully v3.2 endpoint
        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/photos" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3.2/photos" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $params = ['propertyUid' => $propertyUid];

        $parsed = $this->request($endpoints, $params);

        if (!is_array($parsed) || empty($parsed['photos'])) {
            if ($this->logger) {
                $this->logger->log("Photos API returned empty for {$propertyUid}");
            }
            return [];
        }

        if ($this->logger) {
            $this->logger->log("Photos API returned " . count($parsed['photos']) . " photos for {$propertyUid}");
        }

        return $parsed['photos'];
    }

    /**
     * Property details
     */
    public function get_property_details(string $apiKey, string $propertyUid) {

        $endpoints = [
            "https://platform.hostfully.com/api/v3/properties/{$propertyUid}" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3/properties/{$propertyUid}" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        return $this->request($endpoints);
    }

    /**
     * Amenities (v3.2)
     */
    public function get_property_amenities(string $apiKey, string $propertyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3.2/amenities" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3.2/amenities" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $params = ['propertyUid' => $propertyUid];

        $parsed = $this->request($endpoints, $params);

        if (!is_array($parsed) || empty($parsed['amenities'])) {
            return [];
        }

        return $parsed['amenities'];
    }

}