<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Property_Importer (FINAL, with Featured photo fallback and tag fallback)
 *
 * Imports:
 *  - Featured/All property list (auto-fallback)
 *  - Tags (non-fatal if endpoint unavailable; fallback from address/types)
 *  - Photos (REST v3.2 -> fallback to Featured payload/pictureLink)
 *  - Meta
 *  - Amenities → Features taxonomy
 *  - Availability (v3.2 property-calendar)
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
     * MAIN IMPORT (old button / cron)
     * - Auto-fallback: Featured -> All Properties
     */
    public function run_import($apiKey, $agencyUid) {

        if (method_exists($this->logger, 'clear')) {
            $this->logger->clear();
        }
        $this->logger->log("Import started…");

        // 1) Try Featured v3.2
        $props  = $this->api->get_featured_list($apiKey, $agencyUid);
        $source = 'featured';

        // 2) Fallback to "all properties (v3.2)"
        if (!is_array($props) || empty($props)) {
            if (method_exists($this->api, 'get_properties_by_agency')) {
                $props  = $this->api->get_properties_by_agency($apiKey, $agencyUid);
                $source = 'all';
            }
        }

        $count = is_array($props) ? count($props) : 0;
        $this->logger->log("Found {$count} properties via {$source}.");

        foreach ($props as $p) {
            $uid = $p['uid'] ?? ($p['UID'] ?? null);
            if (!$uid) {
                $this->logger->log("Skipped property without UID.");
                continue;
            }
            $this->import_single_property($apiKey, $agencyUid, $uid, $p);
        }

        $this->logger->log("Import complete.");
        if (method_exists($this->logger, 'save')) {
            $this->logger->save();
        }
        return true;
    }

    /**
     * AJAX importer calls this
     */
    public function import_property_from_featured(string $apiKey, array $p) {
        $uid = $p['uid'] ?? ($p['UID'] ?? null);
        if (!$uid) {
            $this->logger->log("Single import: missing UID.");
            return 0;
        }
        return $this->import_single_property($apiKey, null, $uid, $p);
    }

    private function hcn_pick_description_record(array $payload): array {
    // Try common container keys first
    $list = [];
    foreach (['propertyDescriptions','descriptions','items','data'] as $k) {
        if (!empty($payload[$k]) && is_array($payload[$k])) { $list = $payload[$k]; break; }
    }
    // Or it might already be a list
    if (empty($list) && isset($payload[0]) && is_array($payload[0])) $list = $payload;
    if (empty($list)) return [];

    // Prefer UK English then any English, then first
    $prefer = ['en_GB','en-GB','en_US','en-US','en'];
    foreach ($prefer as $loc) {
        foreach ($list as $row) {
        if (!is_array($row)) continue;
        $rowLoc = (string)($row['locale'] ?? $row['language'] ?? '');
        if ($rowLoc && strcasecmp($rowLoc, $loc) === 0) return $row;
        }
    }
    return is_array($list[0]) ? $list[0] : [];
    }

    private function hcn_extract_desc_fields(array $row): array {
    // Be defensive: Hostfully fields can vary by version/account
    $long  = $row['longDescription'] ?? $row['description'] ?? $row['publicDescription'] ?? '';
    $short = $row['shortDescription'] ?? $row['summary'] ?? $row['headline'] ?? '';
    $manual = $row['houseManual'] ?? '';

    return [
        'long'   => is_string($long) ? $long : '',
        'short'  => is_string($short) ? $short : '',
        'manual' => is_string($manual) ? $manual : '',
    ];
    }

    /**
     * Shared per-property import logic
     */
    private function import_single_property(string $apiKey, ?string $agencyUid, string $uid, array $data) {

        $title = $data['name'] ?? ($data['Name'] ?? 'Untitled');
        $this->logger->log("Importing {$title} ({$uid}) …");

        // 0) Fetch full property details (canonical source)
        $details = $this->api->get_property_details($apiKey, $uid);
        $details_p = [];

        if (is_array($details)) {
        // Some APIs wrap the property in a key; be defensive.
        if (isset($details['property']) && is_array($details['property'])) {
            $details_p = $details['property'];
        } else {
            $details_p = $details;
        }
        }

        if (!empty($details_p)) {
        // Merge: details override list/featured payload
        $data = array_merge($data, $details_p);
        $this->logger->log("Property details loaded for {$uid}.");
        } else {
        $this->logger->log("Property details missing/empty for {$uid} (continuing with list payload).");
        }

        // 1) Upsert post
        $post_id = $this->upsert_post($uid, $data);

        // 1b) Descriptions -> post content / excerpt
        if (method_exists($this->api, 'get_property_descriptions')) {
        $desc_payload = $this->api->get_property_descriptions($apiKey, $uid);

        // Store full raw payload (future-proof; doesn't affect frontend speed)
        update_post_meta($post_id, 'hcn_property_descriptions', wp_json_encode($desc_payload));

        $best = is_array($desc_payload) ? $this->hcn_pick_description_record($desc_payload) : [];

        if (!empty($best)) {
            $fields  = $this->hcn_extract_desc_fields($best);
            $content = trim($fields['long']);
            $excerpt = trim($fields['short']);

            $update = ['ID' => $post_id];

            if ($content !== '') {
            // keep basic HTML
            $update['post_content'] = wp_kses_post($content);
            }
            if ($excerpt !== '') {
            // excerpt should be plain text
            $update['post_excerpt'] = wp_strip_all_tags($excerpt);
            }

            if (count($update) > 1) {
            wp_update_post($update);
            $this->logger->log("Descriptions: updated content/excerpt for {$uid}.");
            } else {
            $this->logger->log("Descriptions: record found but long/short empty for {$uid}.");
            }

            // Optional: store house manual separately if present
            if (!empty($fields['manual'])) {
            update_post_meta($post_id, 'hcn_house_manual', wp_kses_post($fields['manual']));
            } else {
            delete_post_meta($post_id, 'hcn_house_manual');
            }
        } else {
            $this->logger->log("Descriptions: none found for {$uid}.");
        }
        }

        // 2) Tags (non-fatal if endpoint unavailable)
        $tags_raw = $this->api->get_property_tags($apiKey, $uid);
        $tags     = $this->normalize_tags($tags_raw);

        // Fallback: derive Location/Group from address + types when the tags API gives nothing
        if (empty($tags)) {
            $fallback = $this->build_fallback_tags_from_property($data);
            if (!empty($fallback)) {
                $this->logger->log("Tags: using fallback (address/types) for {$uid}: " . implode(', ', $fallback));
                $tags = $fallback;
            } else {
                $this->logger->log("Tags: none for post {$post_id} and no fallback found.");
            }
        }

        $this->tax->apply_taxonomies($post_id, $tags);

        // 3) Photos (REST -> fallback to Featured payload / pictureLink)
        $photo_payload = $this->api->get_property_photos($apiKey, $uid);

        if (empty($photo_payload)) {
            // Featured payload sometimes includes a 'photos' array
            if (!empty($data['photos']) && is_array($data['photos'])) {
                $photo_payload = $data['photos'];
                $this->logger->log("Photos: using Featured payload for {$uid} (" . count($photo_payload) . " entries).");
            }
            // Or at least a cover 'pictureLink' URL
            else if (!empty($data['pictureLink']) && is_string($data['pictureLink'])) {
                $photo_payload = [[
                    'uid'                => $uid . '_cover',
                    'displayOrder'       => 0,
                    'largeScaleImageUrl' => $data['pictureLink'],
                ]];
                $this->logger->log("Photos: using pictureLink fallback for {$uid}.");
            }
        }

        $this->photos->sync_from_payload($post_id, (array)$photo_payload);
        // Always ensure no WP attachment thumbnail remains (your Photo_Sync does this too)
        delete_post_thumbnail($post_id);

        // 4) Meta
        $this->update_meta($post_id, $data);

        // 5) Amenities (Features taxonomy)
        $this->sync_property_amenities($apiKey, $uid, $post_id);

        // 6) Availability (v3.2 property-calendar)
        if (isset($GLOBALS['havenconnect']['availability'])) {
            try {
                // Choose a window; your Availability Importer now clamps to 30 days for tests
                $from = gmdate('Y-m-d');
                $to   = gmdate('Y-m-d', strtotime('+365 days'));

                $GLOBALS['havenconnect']['availability']->sync_property_calendar(
                    $apiKey,
                    $uid,
                    $post_id,
                    $from,
                    $to
                );
            } catch (\Throwable $e) {
                $this->logger->log("Availability error for {$uid}: " . $e->getMessage());
            }
        }

        $this->logger->log("Imported {$title} (post_id={$post_id})");
        if (method_exists($this->logger, 'save')) {
            $this->logger->save();
        }
        return $post_id;
    }

    /** -----------------------------------------------------------
     *  AMENITIES → Features taxonomy (per property)
     * ----------------------------------------------------------- */
    private function amenity_display_name(string $code): string {
        // HAS_STOVE -> Stove, IS_LAKEFRONT -> Lakefront
        $clean = preg_replace('/^(HAS_|IS_)/', '', strtoupper($code));
        $clean = str_replace('_', ' ', $clean);
        return ucwords(strtolower($clean));
    }

    private function sync_property_amenities(string $apiKey, string $propertyUid, int $post_id): void {
        $rows = $this->api->get_property_amenities($apiKey, $propertyUid);

        if (empty($rows)) {
            $this->logger->log("Amenities: none for {$propertyUid}");
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
                    $this->logger->log("Amenity term added: {$label}");
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
     * Helpers: fallback tags, upsert, tags normalize, meta
     * ----------------------------------------------------------- */

    /**
     * Build fallback tags when Hostfully returns none.
     * Uses address + property types so Location/Group taxonomies still populate.
     *
     * Our taxonomy handler maps "LOC:*" to 'property_loc' and "GROUP:*" to 'property_group'.
     */
    private function build_fallback_tags_from_property(array $p): array
    {
        $tags = [];

        // Address-based locations
        $addr    = (array)($p['address'] ?? []);
        $city    = trim((string)($addr['city'] ?? ''));
        $state   = trim((string)($addr['state'] ?? ''));
        $country = trim((string)($addr['countryCode'] ?? ''));

        if ($city    !== '') $tags[] = 'LOC:' . $city;
        if ($state   !== '') $tags[] = 'LOC:' . $state;
        if ($country !== '') $tags[] = 'LOC:' . $country;

        // Group from types
        $listingType  = trim((string)($p['listingType']  ?? ''));
        $propertyType = trim((string)($p['propertyType'] ?? ''));

        if ($listingType  !== '') $tags[] = 'GROUP:' . $listingType;
        if ($propertyType !== '') $tags[] = 'GROUP:' . $propertyType;

        return array_values(array_unique($tags));
    }

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
            $this->logger->log("Post error for UID {$uid}: " . $new_id->get_error_message());
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
            'bedrooms'       => $to_int($p['bedrooms'] ?? null),
            'bathrooms'      => $to_float($p['bathrooms'] ?? null),
            'beds'           => $to_int($p['beds'] ?? null),
            'sleeps'         => $to_int($avail['maxGuests'] ?? ($p['maxGuests'] ?? null)),
            'address_line1'  => $addr['address']     ?? null,
            'address_line2'  => $addr['address2']    ?? null,
            'city'           => $addr['city']        ?? null,
            'state'          => $addr['state']       ?? null,
            'postcode'       => $addr['zipCode']     ?? null,
            'country_code'   => $addr['countryCode'] ?? null,
            'latitude'       => $to_float($addr['latitude']  ?? null),
            'longitude'      => $to_float($addr['longitude'] ?? null),
            'property_type'  => $p['propertyType']   ?? null,
            'listing_type'   => $p['listingType']    ?? null,
            'room_type'      => $p['roomType']       ?? null,
            'area_size'      => $to_float($area['size']     ?? null),
            'area_unit'      => $area['unitType']           ?? null,
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