<?php

if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Photo_Sync — UPGRADED VERSION
 *
 * Improvements:
 * - Full logging for each photo
 * - Detects broken / empty / invalid URLs
 * - Fallback chain handles v3.2 and v3.0 fields
 * - Handles Hostfully’s S3 extensionless URLs
 * - Writes:
 *     _hcn_featured_image_url   (string)
 *     _hcn_gallery_urls         (array)
 *     hcn_gallery_url_001..150  (string)
 * - Guaranteed no WP attachments touching
 */

class HavenConnect_Photo_Sync {

    private $logger;

	const META_FEATURED_URL = '_hcn_featured_image_url';
	const META_FEATURED_THUMB = '_hcn_featured_thumb_url';
	const META_GALLERY_URLS = '_hcn_gallery_urls';
	const DISCRETE_PREFIX   = 'hcn_gallery_url_';
	const MAX_URLS          = 150;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Entrypoint: receives Hostfully API photo payload and writes ALL URL metadata.
     */
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

		// De-dupe + cap list
		$urls = array_values(array_unique($urls));
		if (count($urls) > self::MAX_URLS) {
			$this->logger->log("Photos: URL count exceeded " . self::MAX_URLS . ", truncating.");
			$urls = array_slice($urls, 0, self::MAX_URLS);
		}

		// Full-size featured = first sorted URL
		update_post_meta($post_id, self::META_FEATURED_URL, $urls[0]);

		// Thumb = mediumThumbnailScaleImageUrl from first photo (tile-safe size)
		$thumb_url = $this->extract_thumb_url_from_first_photo($photos_payload);
		if ($thumb_url) {
			update_post_meta($post_id, self::META_FEATURED_THUMB, $thumb_url);
			$this->logger->log("Photos: thumb URL written: {$thumb_url}");
		} else {
			// Fallback: use the full-size featured URL so the meta is always populated
			update_post_meta($post_id, self::META_FEATURED_THUMB, $urls[0]);
			$this->logger->log("Photos: no thumb URL found, falling back to featured URL for thumb.");
		}
		
		/**
		 * Extract thumb URL from the first photo in the payload (by displayOrder).
		 * Uses mediumThumbnailScaleImageUrl preferentially — right size for tiles.
		 * Falls back to largeThumbnailScaleImageUrl, then mediumScaleImageUrl.
		 */
		private function extract_thumb_url_from_first_photo(array $photos_payload): string {
			if (empty($photos_payload)) return '';

			// Sort by displayOrder to get the cover photo first
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

		// Full gallery meta
		update_post_meta($post_id, self::META_GALLERY_URLS, $urls);

		// One discrete field per URL
		$this->write_discrete_fields($post_id, $urls);

		// Ensure no WP media attachment interferes
		delete_post_thumbnail($post_id);

		$this->logger->log("Photos: saved " . count($urls) . " URLs (featured + thumb + gallery + discrete).");
	}

    /**
     * Convert photo payload → 1 usable URL per photo, sorted by displayOrder.
     *
     * Fallback priority:
     * 1) largeScaleImageUrl
     * 2) originalImageUrl
     * 3) mediumScaleImageUrl
     * 4) largeThumbnailScaleImageUrl
     * 5) mediumThumbnailScaleImageUrl
     */
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

            // Collect candidates (Hostfully 3.2+3.0 compatible)
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

    /**
     * Write discrete fields (hcn_gallery_url_001..NNN)
     */
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

    /**
     * Delete all hcn_gallery_url_xxx fields.
     */
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

    /**
     * URL validation:
     * - normal extensions (.jpg,.png,.webp,...)
     * - Hostfully S3 URLs (no extension)
     */
    private function is_usable_image_url(string $url): bool {

        // Standard image extensions
        if (preg_match('/\.(jpe?g|png|webp|gif)(\?.*)?$/i', $url)) {
            return true;
        }

        // Hostfully S3 images have no extension
        if (strpos($url, 'orbirental-images.s3.amazonaws.com') !== false) {
            return true;
        }

        // Log rejected URLs for transparency
        $this->logger->log("Photos: rejected non-image URL: {$url}");
        return false;
    }
}