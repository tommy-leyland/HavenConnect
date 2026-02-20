<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Property_Importer
 *
 * Orchestrates:
 *  - Featured property list fetch
 *  - Tag fetch + taxonomy assignment
 *  - Photo sync (external URLs only)
 *  - Post meta updates (LOCK-AWARE)
 *  - Availability import (Hostfully → wp_hcn_availability table)
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
     * Admin UI triggers this method.
     */
    public function run_import($apiKey, $agencyUid) {

        $this->logger->clear();
        $this->logger->log("HavenConnect Import Started");

        // 1. Fetch Featured Properties
        $props = $this->api->get_featured_list($apiKey, $agencyUid);
        $count = count($props);
        $this->logger->log("Found $count Featured properties.");

        foreach ($props as $p) {

            $uid = $p['uid'] ?? ($p['UID'] ?? null);
            if (!$uid) {
                $this->logger->log("Skipped property with no UID.");
                continue;
            }

            // 2. Upsert WP Post
            $post_id = $this->upsert_post($uid, $p);

            // 3. Tags
            $tags_raw = $this->api->get_property_tags($apiKey, $uid);
            $tags     = $this->normalize_tags($tags_raw);
            $this->tax->apply_taxonomies($post_id, $tags);

            // 4. Photos
            $photo_payload = $this->api->get_property_photos($apiKey, $uid);
            $this->photos->sync_from_payload($post_id, $photo_payload);
            delete_post_thumbnail($post_id);

            // 5. Meta (LOCK-AWARE)
            $this->update_meta($post_id, $p);

            // 6. Availability + Pricing Import (passing $apiKey)
            if (isset($GLOBALS['havenconnect']['availability'])) {
                try {
                    $GLOBALS['havenconnect']['availability']->sync_property_calendar($apiKey, $uid);
                    $this->logger->log("Availability: imported calendar for $uid");
                } catch (Throwable $e) {
                    $this->logger->log("Availability ERROR for $uid: " . $e->getMessage());
                }
            }
        }

        $this->logger->log("HavenConnect Import Completed");
        return true;
    }

    /* BUILD AMENITY DICTIONARY */
    public function build_amenity_dictionary(string $apiKey): array {

        $dict = [];
        $props = $this->api->get_featured_list($apiKey);

        foreach ($props as $p) {

            $uid = $p['uid'] ?? null;
            if (!$uid) continue;

            $details = $this->api->get_property_details($apiKey, $uid);
            if (!is_array($details)) continue;

            $amenities = $details['amenities'] ?? [];

            foreach ($amenities as $am) {
                $name = trim($am);
                if ($name !== '') {
                    $dict[$name] = true;
                }
            }
        }

        return array_keys($dict);
    }

    /* Ameneties */
    public function import_default_amenities(string $apiKey)
    {
        $list = $this->build_amenity_dictionary($apiKey);

        foreach ($list as $name) {
            if (!term_exists($name, 'hcn_feature')) {
                wp_insert_term($name, 'hcn_feature');
                $this->logger->log("Added amenity: $name");
            }
        }

        $this->logger->save();
    }

    /**
    * Imports ONE property given the 'Featured list' payload shape.
    * Reuses the existing steps: upsert, tags, photos, meta, availability.
    */
    public function import_property_from_featured(string $apiKey, array $p)
    {
        $uid = $p['uid'] ?? ($p['UID'] ?? null);
        if (!$uid) {
            $this->logger->log("Skipped property with no UID (single import).");
            return 0;
        }

        // Upsert post
        $post_id = $this->upsert_post($uid, $p);

        // Tags → taxonomies
        $tags_raw = $this->api->get_property_tags($apiKey, $uid);
        $tags     = $this->normalize_tags($tags_raw);
        $this->tax->apply_taxonomies($post_id, $tags);

        // Photos
        $photo_payload = $this->api->get_property_photos($apiKey, $uid);
        $this->photos->sync_from_payload($post_id, $photo_payload);
        delete_post_thumbnail($post_id);

        // Meta (lock-aware)
        $this->update_meta($post_id, $p);

        // Availability (OAuth later; safe to call; it no-ops if no data)
        if (isset($GLOBALS['havenconnect']['availability'])) {
            try {
                $GLOBALS['havenconnect']['availability']->sync_property_calendar($apiKey, $uid);
                $this->logger->log("Availability: imported calendar for $uid");
            } catch (Throwable $e) {
                $this->logger->log("Availability ERROR for $uid: " . $e->getMessage());
            }
        }

        return (int)$post_id;
    }

    /**
     * Create or update a post representing a PMS property.
     */
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
            $this->logger->log("WP Error creating/updating post for UID $uid: ".$new_id->get_error_message());
            return 0;
        }

        $post_id = $new_id;

        // UID is system meta; never locked
        update_post_meta($post_id, '_havenconnect_uid', $uid);

        $this->logger->log("Upserted: $title (post_id={$post_id})");
        return $post_id;
    }

    /**
     * Normalize raw tags from Hostfully.
     */
    private function normalize_tags($raw): array {

        if (empty($raw)) return [];

        $flat = [];

        $walk = function($v) use (&$flat, &$walk) {
            if (is_string($v)) {
                $flat[] = trim($v);
                return;
            }
            if (is_array($v)) {
                if (isset($v['name']) && is_string($v['name'])) {
                    $flat[] = trim($v['name']);
                    return;
                }
                foreach ($v as $vv) $walk($vv);
            }
        };

        $walk($raw);

        // Remove spaces in "[g] Something"
        $flat = array_map(function($t){
            return preg_replace('/^\[(l|g)\]\s+/i', '[$1]', $t);
        }, $flat);

        return array_values(array_unique(array_filter($flat)));
    }

    /**
     * LOCK-AWARE meta write helper.
     * - If <key>_locked is set, we DO NOT overwrite or delete the field.
     * - If $value is "empty" (null, empty string, empty array), we DELETE the meta (unless locked).
     * - Otherwise, we UPDATE the meta value.
     */
    private function safe_update_meta(int $post_id, string $key, $value): void
    {
        $lock_key = "{$key}_locked";
        $is_locked = (bool) get_post_meta($post_id, $lock_key, true);

        if ($is_locked) {
            // Skip any change when locked
            $this->logger->log("LOCKED: Skipped '{$key}' for post {$post_id}");
            return;
        }

        $is_empty = false;
        if ($value === null) {
            $is_empty = true;
        } elseif (is_string($value) && trim($value) === '') {
            $is_empty = true;
        } elseif (is_array($value) && count($value) === 0) {
            $is_empty = true;
        }

        if ($is_empty) {
            delete_post_meta($post_id, $key);
            return;
        }

        update_post_meta($post_id, $key, $value);
    }

    /**
     * Native post meta mapping (LOCK-AWARE, no ACF, no attachments)
     */
    private function update_meta(int $post_id, array $p): void
    {
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

            'check_in_from' => $to_int($avail['checkInTimeStart'] ?? null),
            'check_in_to'   => $to_int($avail['checkInTimeEnd']   ?? null),
            'min_stay'      => $to_int($avail['minimumStay']      ?? null),
            'max_stay'      => $to_int($avail['maximumStay']      ?? null),

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
            'floor_count'   => $to_int($p['numberOfFloors'] ?? null),

            'area_size'     => $to_float($area['size']     ?? null),
            'area_unit'     => $area['unitType']           ?? null,

            'license_number' => $p['rentalLicenseNumber']         ?? null,
            'license_expiry' => $p['rentalLicenseExpirationDate'] ?? null,
        ];

        foreach ($map as $key => $value) {
            $this->safe_update_meta($post_id, $key, $value);
        }

        $this->logger->log("Meta: updated (lock-aware) fields for post $post_id");
    }
}