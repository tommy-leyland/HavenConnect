<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Property_Importer
 *
 * Imports:
 *  - Featured list
 *  - Tags
 *  - Photos
 *  - Meta
 *  - Amenities (NEW)
 *  - Availability (OAuth pending)
 */
class HavenConnect_Property_Importer {

    private $api;
    private $tax;
    private $photos;
    private $logger;

    public function __construct($api_client, $taxonomy_handler, $photo_sync, $logger) {
        $this->api    = $api_client;
        $this->tax    = $taxonomy_handler;
        $this->photos = $photo_sync;
        $this->logger = $logger;
    }

    /**
     * MAIN IMPORT LOOP (Used by old button & Cron)
     */
    public function run_import($apiKey, $agencyUid) {

        $this->logger->clear();
        $this->logger->log("Import started…");

        $props = $this->api->get_featured_list($apiKey, $agencyUid);
        $count = count($props);
        $this->logger->log("Found $count Featured properties.");

        foreach ($props as $p) {
            $uid = $p['uid'] ?? ($p['UID'] ?? null);
            if (!$uid) {
                $this->logger->log("Skipped property without UID.");
                continue;
            }

            $this->import_single_property($apiKey, $agencyUid, $uid, $p);
        }

        $this->logger->log("Import complete.");
        return true;
    }

    /**
     * AJAX importer calls this — but it is also shared by run_import()
     */
    public function import_property_from_featured(string $apiKey, array $p) {
        $uid = $p['uid'] ?? ($p['UID'] ?? null);
        if (!$uid) {
            $this->logger->log("Single import: missing UID.");
            return 0;
        }

        return $this->import_single_property($apiKey, null, $uid, $p);
    }

    /**
     * Shared per-property import logic
     */
    private function import_single_property(string $apiKey, ?string $agencyUid, string $uid, array $data) {

        // Upsert post
        $post_id = $this->upsert_post($uid, $data);

        // Tags
        $tags_raw = $this->api->get_property_tags($apiKey, $uid);
        $tags     = $this->normalize_tags($tags_raw);
        $this->tax->apply_taxonomies($post_id, $tags);

        // Photos
        $photo_payload = $this->api->get_property_photos($apiKey, $uid);
        $this->photos->sync_from_payload($post_id, $photo_payload);
        delete_post_thumbnail($post_id);

        // Meta
        $this->update_meta($post_id, $data);

        // Amenities → Features taxonomy
        $this->sync_property_amenities($apiKey, $uid, $post_id);

        // Availability (OAuth pending)
        if (isset($GLOBALS['havenconnect']['availability'])) {
            try {
                if ($agencyUid) {
                    $GLOBALS['havenconnect']['availability']->sync_property_calendar($apiKey, $uid);
                }
            } catch (\Throwable $e) {
                $this->logger->log("Availability error for $uid: " . $e->getMessage());
            }
        }

        return $post_id;
    }

    /** -----------------------------------------------------------
     *  AMENITIES → Features taxonomy
     * ----------------------------------------------------------- */

    private function amenity_display_name(string $code): string {
        // HAS_STOVE → Stove
        // IS_LAKEFRONT → Lakefront
        $clean = preg_replace('/^(HAS_|IS_)/', '', strtoupper($code));
        $clean = str_replace('_', ' ', $clean);
        return ucwords(strtolower($clean));
    }

    private function sync_property_amenities(string $apiKey, string $propertyUid, int $post_id): void {

        $rows = $this->api->get_property_amenities($apiKey, $propertyUid);

        if (empty($rows)) {
            $this->logger->log("Amenities: none for $propertyUid");
            return;
        }

        $terms = [];

        foreach ($rows as $row) {
            $code = isset($row['amenity']) ? trim($row['amenity']) : '';
            if ($code === '') continue;

            $label = $this->amenity_display_name($code);
            if ($label === '') continue;

            if (!term_exists($label, 'hcn_feature')) {
                $res = wp_insert_term($label, 'hcn_feature');
                if (!is_wp_error($res)) {
                    $this->logger->log("Amenity term added: $label");
                }
            }

            $terms[] = $label;
        }

        if (!empty($terms)) {
            wp_set_object_terms($post_id, $terms, 'hcn_feature', false);
            $this->logger->log("Amenities assigned to post {$post_id}: " . implode(', ', $terms));
        }
    }

    /** -----------------------------------------------------------
     * Existing methods (upsert_post, normalize_tags, update_meta)
     * ----------------------------------------------------------- */

    private function upsert_post(string $uid, array $data): int {

        $title = sanitize_text_field($data['name'] ?? ($data['Name'] ?? 'Untitled'));

        $existing = get_posts([
            'post_type'   => 'hcn_property',
            'meta_key'    => '_havenconnect_uid',
            'meta_value'  => $uid,
            'post_status' => ['publish','draft','pending','private'],
            'fields'      => 'ids',
            'numberposts' => 1
        ]);

        $post_id = $existing ? (int)$existing[0] : 0;

        $postarr = [
            'ID'          => $post_id,
            'post_type'   => 'hcn_property',
            'post_title'  => $title,
            'post_status' => $post_id ? get_post_status($post_id) : 'publish',
        ];

        $new_id = $post_id ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);

        if (is_wp_error($new_id)) {
            $this->logger->log("Post error for UID $uid: ".$new_id->get_error_message());
            return 0;
        }

        $post_id = $new_id;
        update_post_meta($post_id, '_havenconnect_uid', $uid);

        return $post_id;
    }

    private function normalize_tags($raw): array {
        if (empty($raw)) return [];
        $flat = [];

        $walk = function($v) use (&$flat, &$walk) {
            if (is_string($v)) { $flat[] = trim($v); return; }
            if (is_array($v)) {
                if (isset($v['name']) && is_string($v['name'])) {
                    $flat[] = trim($v['name']);
                    return;
                }
                foreach ($v as $vv) $walk($vv);
            }
        };
        $walk($raw);

        return array_values(array_unique(array_filter($flat)));
    }

    private function update_meta(int $post_id, array $p): void {

        $addr  = (array)($p['address']      ?? []);
        $avail = (array)($p['availability'] ?? []);
        $area  = (array)($p['area']         ?? []);

        $to_int   = fn($v) => is_numeric($v) ? (int)$v   : null;
        $to_float = fn($v) => is_numeric($v) ? (float)$v : null;

        $map = [
            'bedrooms'      => $to_int($p['bedrooms'] ?? null),
            'bathrooms'     => $to_float($p['bathrooms'] ?? null),
            'beds'          => $to_int($p['beds'] ?? null),
            'sleeps'        => $to_int($avail['maxGuests'] ?? ($p['maxGuests'] ?? null)),
            'address_line1' => $addr['address']     ?? null,
            'address_line2' => $addr['address2']    ?? null,
            'city'          => $addr['city']        ?? null,
            'state'         => $addr['state']       ?? null,
            'postcode'      => $addr['zipCode']     ?? null,
            'country_code'  => $addr['countryCode'] ?? null,
            'latitude'      => $to_float($addr['latitude']  ?? null),
            'longitude'     => $to_float($addr['longitude'] ?? null),
            'property_type' => $p['propertyType']   ?? null,
            'listing_type'  => $p['listingType']    ?? null,
            'room_type'     => $p['roomType']       ?? null,
            'area_size'     => $to_float($area['size']     ?? null),
            'area_unit'     => $area['unitType']           ?? null,
            'license_number' => $p['rentalLicenseNumber'] ?? null,
            'license_expiry' => $p['rentalLicenseExpirationDate'] ?? null,
        ];

        foreach ($map as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                update_post_meta($post_id, $key, $value);
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }
}