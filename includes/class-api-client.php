<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Api_Client
 *
 * Minimal, clean API client used by the importer & admin.
 * Handles Featured Properties, Tags, Photos, Property Details, Amenities.
 */
class HavenConnect_Api_Client {

    private $logger;

    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /**
     * Core HTTP wrapper.
     * Accepts array of endpoints (primary + fallback).
     * $params appended as query string.
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
                continue; // try next endpoint
            }

            $code = wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) {
                continue;
            }

            $body = wp_remote_retrieve_body($res);
            if (!$body) return null;

            $parsed = json_decode($body, true);
            return $parsed;
        }

        return null;
    }

    /**
     * Featured property list for an Agency UID.
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
     * Get tags for a property.
     */
    public function get_property_tags(string $apiKey, string $propertyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3/properties/{$propertyUid}/tags" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3/properties/{$propertyUid}/tags" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $parsed = $this->request($endpoints);

        if (!is_array($parsed) || empty($parsed['tags'])) {
            return [];
        }

        return $parsed['tags'];
    }

    /**
     * Fetch property photos
     */
    public function get_property_photos(string $apiKey, string $propertyUid): array {

        $endpoints = [
            "https://platform.hostfully.com/api/v3/properties/{$propertyUid}/photos" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
            "https://sandbox.hostfully.com/api/v3/properties/{$propertyUid}/photos" => [
                "X-HOSTFULLY-APIKEY" => $apiKey
            ],
        ];

        $parsed = $this->request($endpoints);

        if (!is_array($parsed) || empty($parsed['photos'])) {
            return [];
        }

        return $parsed['photos'];
    }

    /**
     * Property details (used occasionally)
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

        $parsed = $this->request($endpoints);
        return $parsed;
    }

    /**
     * Amenities v3.2 — per-property
     *
     * https://platform.hostfully.com/api/v3.2/amenities?propertyUid={UID}
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

}