<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Availability_Importer (FINAL)
 *
 * - Pulls daily entries from Hostfully v3.2 property calendar endpoint.
 * - Writes to wp_hcn_availability table if it exists (preferred),
 *   otherwise falls back to post meta so the data is still visible to the site.
 * - Clears the selected date range before writing new entries.
 * - Logs verbosely for transparency.
 */
class HavenConnect_Availability_Importer {

    /** @var HavenConnect_Api_Client */
    private $api;

    /** @var HavenConnect_Logger */
    private $logger;

    /** @var string */
    private $table;

    public function __construct($api_client, $logger) {
        global $wpdb;
        $this->api    = $api_client;
        $this->logger = $logger;
        $this->table  = $wpdb->prefix . 'hcn_availability';
    }

    /**
     * Sync property calendar into DB or post meta.
     *
     * @param string      $apiKey
     * @param string      $propertyUid
     * @param int         $post_id
     * @param string|null $from (Y-m-d) default: today
     * @param string|null $to   (Y-m-d) default: today + 365 days
     */
    public function sync_property_calendar(string $apiKey, string $propertyUid, int $post_id, ?string $from = null, ?string $to = null): void
    {
        // Date range defaults
        $nowTs = current_time('timestamp'); // WP-local
        $from  = $from ?: gmdate('Y-m-d', $nowTs);
        $to    = $to   ?: gmdate('Y-m-d', strtotime('+365 days', $nowTs));

		// --- ONE-MONTH TEST LIMIT (clamp) ---
			// Force $to to be no more than 30 days after $from
			$fromTs   = strtotime($from . ' 00:00:00 UTC');
			$toTs     = strtotime($to   . ' 00:00:00 UTC');
			$maxToTs  = strtotime('+30 days', $fromTs);

			if ($toTs === false || $fromTs === false) {
				// Fallback: if parsing failed, just set a safe 30-day window from today
				$from = gmdate('Y-m-d');
				$to   = gmdate('Y-m-d', strtotime('+30 days'));
				$this->logger->log("Availability: invalid dates provided; defaulted to {$from}→{$to} for test.");
			} elseif ($toTs > $maxToTs) {
				$to = gmdate('Y-m-d', $maxToTs);
				$this->logger->log("Availability: clamped date range to {$from}→{$to} (max 30 days for test).");
			}
		// ------------------------------------

        $this->logger->log("Availability: fetching {$propertyUid} {$from}→{$to}");

        // Fetch from API
        $entries = $this->api->get_property_calendar($apiKey, $propertyUid, $from, $to);
        if (empty($entries)) {
            $this->logger->log("Availability: no entries returned for {$propertyUid} {$from}→{$to}");
            return;
        }

        // Decide destination (table vs postmeta)
        $use_table = $this->table_exists_and_has_min_columns();

        if ($use_table) {
            $this->logger->log("Availability: writing to table {$this->table}");
            $this->write_to_table($entries, $post_id, $propertyUid, $from, $to);
        } else {
            $this->logger->log("Availability: table missing; writing to post meta instead.");
            $this->write_to_meta($entries, $post_id, $from, $to);
        }

        $this->logger->log("Availability: sync complete for {$propertyUid} {$from}→{$to}");
    }

    /**
     * Check the install table exists & has minimal expected columns.
     */
    private function table_exists_and_has_min_columns(): bool
    {
        global $wpdb;

        // Fast existence check
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table));
        if ($exists !== $this->table) return false;

        // Minimal columns we rely on
        $needed = ['post_id', 'property_uid', 'for_date', 'price', 'currency', 'unavailable'];
        $cols   = $wpdb->get_results("DESCRIBE {$this->table}", ARRAY_A);
        if (!is_array($cols)) return false;

        $names = array_map(fn($c) => $c['Field'], $cols);
        foreach ($needed as $n) {
            if (!in_array($n, $names, true)) return false;
        }
        return true;
    }

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

		if ($city !== '')    $tags[] = 'LOC:' . $city;
		if ($state !== '')   $tags[] = 'LOC:' . $state;
		if ($country !== '') $tags[] = 'LOC:' . $country;

		// Group from types
		$listingType  = trim((string)($p['listingType']  ?? ''));
		$propertyType = trim((string)($p['propertyType'] ?? ''));

		if ($listingType !== '')  $tags[] = 'GROUP:' . $listingType;
		if ($propertyType !== '') $tags[] = 'GROUP:' . $propertyType;

		return array_values(array_unique($tags));
	}

    /**
     * Delete existing rows in the given range, then insert fresh.
     */
    private function write_to_table(array $entries, int $post_id, string $propertyUid, string $from, string $to): void
    {
        global $wpdb;

        // Clear previous range to avoid duplicates
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE post_id = %d AND for_date BETWEEN %s AND %s",
                $post_id, $from, $to
            )
        );

        $inserted = 0;
        foreach ($entries as $e) {
            if (!is_array($e)) continue;

            $date = $e['date'] ?? null;
            if (!$date) continue;

            $pricing = (array)($e['pricing'] ?? []);
            $avail   = (array)($e['availability'] ?? []);

            $price     = isset($pricing['value']) ? (float)$pricing['value'] : null;
            $currency  = $pricing['currency'] ?? null;

            $unavail   = !empty($avail['unavailable']) ? 1 : 0;
            $reason    = $avail['unavailabilityReason'] ?? null;
            $ci        = isset($avail['availableForCheckIn'])  ? (int)!empty($avail['availableForCheckIn'])  : null;
            $co        = isset($avail['availableForCheckOut']) ? (int)!empty($avail['availableForCheckOut']) : null;
            $minStay   = isset($avail['minimumStayLength']) ? (int)$avail['minimumStayLength'] : null;
            $maxStay   = isset($avail['maximumStayLength']) ? (int)$avail['maximumStayLength'] : null;
            $notes     = $avail['notes'] ?? null;

            $wpdb->insert(
                $this->table,
                [
                    'post_id'        => $post_id,
                    'property_uid'   => $propertyUid,
                    'for_date'       => $date,
                    'price'          => $price,
                    'currency'       => $currency,
                    'unavailable'    => $unavail,
                    'reason'         => $reason,
                    'checkin'        => $ci,
                    'checkout'       => $co,
                    'min_stay'       => $minStay,
                    'max_stay'       => $maxStay,
                    'notes'          => $notes,
                    'updated_at'     => gmdate('Y-m-d H:i:s'),
                ],
                [
                    '%d','%s','%s','%f','%s','%d','%s','%d','%d','%d','%d','%s','%s'
                ]
            );
            if (!$wpdb->last_error) $inserted++;
        }

        $this->logger->log("Availability: inserted {$inserted} rows into {$this->table} for post {$post_id}.");
    }

    /**
     * Fallback: store each date as an associative array in post meta.
     * - Removes old meta for the range first (to keep it tidy).
     * - Stores a compact array keyed by date under one aggregate meta key.
     */
    private function write_to_meta(array $entries, int $post_id, string $from, string $to): void
    {
        // Build a clean map(date => dayData)
        $map = [];

        foreach ($entries as $e) {
            if (!is_array($e)) continue;

            $date = $e['date'] ?? null;
            if (!$date) continue;

            $pricing = (array)($e['pricing'] ?? []);
            $avail   = (array)($e['availability'] ?? []);

            $map[$date] = [
                'price'       => isset($pricing['value']) ? (float)$pricing['value'] : null,
                'currency'    => $pricing['currency'] ?? null,
                'unavailable' => !empty($avail['unavailable']),
                'reason'      => $avail['unavailabilityReason'] ?? null,
                'checkin'     => !empty($avail['availableForCheckIn']),
                'checkout'    => !empty($avail['availableForCheckOut']),
                'min_stay'    => isset($avail['minimumStayLength']) ? (int)$avail['minimumStayLength'] : null,
                'max_stay'    => isset($avail['maximumStayLength']) ? (int)$avail['maximumStayLength'] : null,
                'notes'       => $avail['notes'] ?? null,
            ];
        }

        // Store the whole map into one namespaced meta key per year chunk (keeps scale sane).
        // For simplicity now, one key per sync:
        update_post_meta($post_id, '_hcn_availability_map', $map);

        $this->logger->log("Availability: stored ".count($map)." days into post meta for post {$post_id}.");
    }
}