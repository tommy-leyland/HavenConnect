<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Photo_Sync — Option B (External URLs only, discrete text fields)
 *
 * - NO local files / attachments.
 * - Writes:
 *     _hcn_featured_image_url   (string)
 *     _hcn_gallery_urls         (array of strings)
 *     hcn_gallery_url_001..NNN  (string; one meta row per URL)
 * - Clears _thumbnail_id so WP won't try to use broken attachments.
 */
class HavenConnect_Photo_Sync {

    private $logger;

    // Meta keys
    const META_FEATURED_URL = '_hcn_featured_image_url';
    const META_GALLERY_URLS = '_hcn_gallery_urls';
    const DISCRETE_PREFIX   = 'hcn_gallery_url_'; // hcn_gallery_url_001..NNN

    // Safety cap (change if you want more)
    const MAX_URLS = 150;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Entry point: consume Hostfully photos payload and store pure URLs only.
     */
    public function sync_from_payload(int $post_id, array $photos_payload): void {

        $urls = $this->extract_urls_one_per_photo_sorted($photos_payload);

        if (empty($urls)) {
            $this->logger->log("Photos: no URLs for post $post_id.");
            // Clean old state
            delete_post_thumbnail($post_id);
            delete_post_meta($post_id, self::META_FEATURED_URL);
            delete_post_meta($post_id, self::META_GALLERY_URLS);
            $this->delete_discrete_fields($post_id);
            return;
        }

        // Cap and de-dup
        $urls = array_values(array_unique($urls));
        if (count($urls) > self::MAX_URLS) {
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        // 1) Featured (cover) = first by displayOrder
        update_post_meta($post_id, self::META_FEATURED_URL, $urls[0]);

        // 2) Store the whole set as one array (handy for code)
        update_post_meta($post_id, self::META_GALLERY_URLS, $urls);

        // 3) Store one URL per text field (hcn_gallery_url_001..NNN)
        $this->write_discrete_fields($post_id, $urls);

        // 4) Guarantee NO WP attachment thumbnail remains
        delete_post_thumbnail($post_id);

        $this->logger->log("Photos: saved ".count($urls)." URLs (featured meta + discrete text fields).");
    }

    /**
     * Make exactly one URL per photo.
     * Priority: largeScaleImageUrl > originalImageUrl > mediumScaleImageUrl > largeThumbnailScaleImageUrl > mediumThumbnailScaleImageUrl
     * Sort ascending by displayOrder.
     */
    private function extract_urls_one_per_photo_sorted(array $photos_payload): array {
        if (empty($photos_payload)) return [];

        $byUid = [];
        foreach ($photos_payload as $photo) {
            if (!is_array($photo)) continue;
            $uid = $photo['uid'] ?? null;
            if (!$uid) continue;

            $candidates = [
                $photo['largeScaleImageUrl']            ?? null,
                $photo['originalImageUrl']              ?? null,
                $photo['mediumScaleImageUrl']           ?? null,
                $photo['largeThumbnailScaleImageUrl']   ?? null,
                $photo['mediumThumbnailScaleImageUrl']  ?? null,
            ];

            $chosen = null;
            foreach ($candidates as $u) {
                if (!$u) continue;
                if ($this->is_usable_image_url($u)) { $chosen = trim($u); break; }
            }

            if ($chosen) {
                $byUid[$uid] = [
                    'order' => (int)($photo['displayOrder'] ?? 999999),
                    'url'   => $chosen
                ];
            }
        }

        if (!$byUid) return [];
        uasort($byUid, fn($a,$b) => $a['order'] <=> $b['order']);
        return array_values(array_map(fn($x) => $x['url'], $byUid));
    }

    /**
     * Write one meta per URL:
     *   hcn_gallery_url_001, hcn_gallery_url_002, ...
     * Delete stale extras if the new list is shorter.
     */
    private function write_discrete_fields(int $post_id, array $urls): void {
        // Delete stale fields first (in case previous run had more)
        $this->delete_discrete_fields($post_id);

        $i = 0;
        foreach ($urls as $u) {
            $i++;
            $key = $this->format_discrete_key($i);
            update_post_meta($post_id, $key, $u);
        }
    }

    private function delete_discrete_fields(int $post_id): void {
        // Load all metas and remove those that match the prefix
        $all = get_post_meta($post_id);
        foreach ($all as $k => $_vals) {
            if (strpos($k, self::DISCRETE_PREFIX) === 0) {
                delete_post_meta($post_id, $k);
            }
        }
    }

    private function format_discrete_key(int $index): string {
        // zero-padded 3 digits (001..150) – adjust padding if you want
        return self::DISCRETE_PREFIX . str_pad((string)$index, 3, '0', STR_PAD_LEFT);
    }

    private function is_usable_image_url(string $url): bool {
        if (preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $url)) return true;
        if (strpos($url, 'orbirental-images.s3.amazonaws.com') !== false) return true; // Hostfully S3 objects (extensionless)
        return false;
    }
}