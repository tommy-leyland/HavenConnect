<?php

if (!defined('ABSPATH')) exit;

class HavenConnect_Photo_Sync {

    private $logger;

    const META_FEATURED_URL   = '_hcn_featured_image_url';
    const META_FEATURED_THUMB = '_hcn_featured_thumb_url';
    const META_GALLERY_URLS   = '_hcn_gallery_urls';
    const DISCRETE_PREFIX     = 'hcn_gallery_url_';
    const MAX_URLS            = 150;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function sync_from_payload(int $post_id, array $photos_payload): void {

        $this->logger->log("Photos: processing payload for post {$post_id}…");

        $urls = $this->extract_urls_one_per_photo_sorted($photos_payload);

        if (empty($urls)) {
            $this->logger->log("Photos: no usable URLs — clearing old meta.");
            delete_post_thumbnail($post_id);
            delete_post_meta($post_id, self::META_FEATURED_URL);
            delete_post_meta($post_id, self::META_FEATURED_THUMB);
            delete_post_meta($post_id, self::META_GALLERY_URLS);
            $this->delete_discrete_fields($post_id);
            return;
        }

        $urls = array_values(array_unique($urls));
        if (count($urls) > self::MAX_URLS) {
            $this->logger->log("Photos: URL count exceeded " . self::MAX_URLS . ", truncating.");
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        update_post_meta($post_id, self::META_FEATURED_URL, $urls[0]);

        $thumb_url = $this->extract_thumb_url_from_first_photo($photos_payload);
        if ($thumb_url) {
            update_post_meta($post_id, self::META_FEATURED_THUMB, $thumb_url);
            $this->logger->log("Photos: thumb URL written: {$thumb_url}");
        } else {
            update_post_meta($post_id, self::META_FEATURED_THUMB, $urls[0]);
            $this->logger->log("Photos: no thumb URL found, falling back to featured URL for thumb.");
        }

        update_post_meta($post_id, self::META_GALLERY_URLS, $urls);
        $this->write_discrete_fields($post_id, $urls);
        delete_post_thumbnail($post_id);

        $this->logger->log("Photos: saved " . count($urls) . " URLs (featured + thumb + gallery + discrete).");
    }

    private function extract_urls_one_per_photo_sorted(array $photos_payload): array {
        if (empty($photos_payload)) return [];

        $this->logger->log("Photos: payload contains " . count($photos_payload) . " entries.");

        $output = [];

        foreach ($photos_payload as $photo) {
            if (!is_array($photo)) {
                $this->logger->log("Photos: skipping non-array photo entry.");
                continue;
            }

            $uid = $photo['uid'] ?? null;
            if (!$uid) {
                $this->logger->log("Photos: skipping entry with no UID.");
                continue;
            }

            $candidates = [
                $photo['largeScaleImageUrl']           ?? null,
                $photo['originalImageUrl']             ?? null,
                $photo['mediumScaleImageUrl']          ?? null,
                $photo['largeThumbnailScaleImageUrl']  ?? null,
                $photo['mediumThumbnailScaleImageUrl'] ?? null,
            ];

            $chosen = null;
            foreach ($candidates as $u) {
                if (!$u) continue;
                if ($this->is_usable_image_url($u)) {
                    $chosen = trim($u);
                    break;
                }
            }

            if (!$chosen) {
                $this->logger->log("Photos: no valid URL candidates for photo UID {$uid}.");
                continue;
            }

            $order = (int)($photo['displayOrder'] ?? 999999);
            $this->logger->log("Photos: chosen URL for UID {$uid} (order {$order}): {$chosen}");

            $output[$uid] = [
                'order' => $order,
                'url'   => $chosen,
            ];
        }

        if (empty($output)) {
            $this->logger->log("Photos: after filtering, zero usable URLs.");
            return [];
        }

        uasort($output, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_values(array_map(fn($entry) => $entry['url'], $output));
    }

    private function extract_thumb_url_from_first_photo(array $photos_payload): string {
        if (empty($photos_payload)) return '';

        $sorted = array_filter($photos_payload, fn($p) => is_array($p) && isset($p['uid']));
        usort($sorted, fn($a, $b) => (int)($a['displayOrder'] ?? 999999) <=> (int)($b['displayOrder'] ?? 999999));

        $first = reset($sorted);
        if (!$first) return '';

        $candidates = [
            $first['mediumThumbnailScaleImageUrl'] ?? null,
            $first['largeThumbnailScaleImageUrl']  ?? null,
            $first['mediumScaleImageUrl']          ?? null,
        ];

        foreach ($candidates as $u) {
            if ($u && $this->is_usable_image_url(trim($u))) {
                return trim($u);
            }
        }

        return '';
    }

    private function write_discrete_fields(int $post_id, array $urls): void {
        $this->delete_discrete_fields($post_id);

        $index = 1;
        foreach ($urls as $url) {
            $meta_key = $this->format_discrete_key($index);
            update_post_meta($post_id, $meta_key, $url);
            $this->logger->log("Photos: wrote {$meta_key} → {$url}");
            $index++;
            if ($index > self::MAX_URLS) break;
        }
    }

    private function delete_discrete_fields(int $post_id): void {
        $all = get_post_meta($post_id);
        foreach ($all as $meta_key => $_values) {
            if (strpos($meta_key, self::DISCRETE_PREFIX) === 0) {
                delete_post_meta($post_id, $meta_key); 
            }
        }
    }

    private function format_discrete_key(int $i): string {
        return self::DISCRETE_PREFIX . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    }

    private function is_usable_image_url(string $url): bool {
        if (preg_match('/\.(jpe?g|png|webp|gif)(\?.*)?$/i', $url)) {
            return true;
        }
        if (strpos($url, 'orbirental-images.s3.amazonaws.com') !== false) {
            return true;
        }
        $this->logger->log("Photos: rejected non-image URL: {$url}");
        return false;
    }
}