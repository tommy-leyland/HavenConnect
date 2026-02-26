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

    public function sync_loggia_property_calendar(
      string $base_url,
      string $api_key,
      string $page_id,
      string $locale,
      string $loggia_property_id,
      int $post_id,
      ?string $from = null,
      ?string $to = null
    ): void {
      // Date range defaults (same style as Hostfully)
      $nowTs = current_time('timestamp');
      $from  = $from ?: gmdate('Y-m-d', $nowTs);
      $to    = $to   ?: gmdate('Y-m-d', strtotime('+365 days', $nowTs));

      // (Optional) keep your 30-day clamp while testing, same as Hostfully method
      $fromTs  = strtotime($from . ' 00:00:00 UTC');
      $toTs    = strtotime($to   . ' 00:00:00 UTC');
      $maxToTs = strtotime('+30 days', $fromTs);
      if ($toTs === false || $fromTs === false) {
        $from = gmdate('Y-m-d');
        $to   = gmdate('Y-m-d', strtotime('+30 days'));
        $this->logger->log("Loggia Availability: invalid dates provided; defaulted to {$from}→{$to} for test.");
      } elseif ($toTs > $maxToTs) {
        $to = gmdate('Y-m-d', $maxToTs);
        $this->logger->log("Loggia Availability: clamped date range to {$from}→{$to} (max 30 days for test).");
      }

      $this->logger->log("Loggia Availability: fetching {$loggia_property_id} {$from}→{$to}");

      // Load Loggia client
      $client_path = defined('HCN_PATH')
        ? HCN_PATH . 'includes/providers/loggia/class-loggia-client.php'
        : (plugin_dir_path(__FILE__) . 'providers/loggia/class-loggia-client.php');

      if (file_exists($client_path)) require_once $client_path;
      if (!class_exists('HavenConnect_Loggia_Client')) {
        $this->logger->log("Loggia Availability: client class missing.");
        return;
      }

      $client = new HavenConnect_Loggia_Client($base_url, $api_key, $this->logger);

      // Call v2 list for range (returns properties[] each with availability map)
      if (!method_exists($client, 'list_properties_v2')) {
        $this->logger->log("Loggia Availability: client missing list_properties_v2().");
        return;
      }

      $resp = $client->list_properties_v2($page_id, $locale, $from, $to, 0, 200, false);
      if (!is_array($resp) || empty($resp['properties']) || !is_array($resp['properties'])) {
        $this->logger->log("Loggia Availability: no properties returned for {$from}→{$to}");
        return;
      }

      // Find our property in the list response
      $prop = null;
      foreach ($resp['properties'] as $p) {
        if (!is_array($p)) continue;
        $pid = (string)($p['property_id'] ?? $p['id'] ?? '');
        if ($pid === (string)$loggia_property_id) { $prop = $p; break; }
      }

      if (!$prop || empty($prop['availability']) || !is_array($prop['availability'])) {
        $this->logger->log("Loggia Availability: property {$loggia_property_id} not found or no availability map.");
        return;
      }

      // Transform Loggia map => Hostfully-like $entries structure that write_to_table expects
      $entries = [];
      foreach ($prop['availability'] as $day => $info) {
        if (!is_array($info)) continue;
        $date = (string)($info['date'] ?? $day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        $status_desc = strtolower((string)($info['status_desc'] ?? ''));
        $price = isset($info['price']) && $info['price'] !== '' ? (float)$info['price'] : null;

        // available only if status says available AND we have a price
        $is_available = ($status_desc === 'available') && ($price !== null);

        $entries[] = [
          'date' => $date,
          'pricing' => [
            'value'    => $price,
            'currency' => null, // Loggia v2 payload doesn't include currency
          ],
          'availability' => [
            'unavailable'           => $is_available ? false : true,
            'availableForCheckIn'   => isset($info['checkinAllowed'])  ? (bool)$info['checkinAllowed']  : null,
            'availableForCheckOut'  => isset($info['checkoutAllowed']) ? (bool)$info['checkoutAllowed'] : null,
            'minimumStayLength'     => isset($info['min_stay']) && $info['min_stay'] !== null ? (int)$info['min_stay'] : null,
            'maximumStayLength'     => null,
          ],
        ];
      }

      if (empty($entries)) {
        $this->logger->log("Loggia Availability: no day entries to write for {$loggia_property_id}.");
        return;
      }

      // Write using SAME writer as Hostfully (table if exists, else meta)
      $use_table = $this->table_exists_and_has_min_columns();
      if ($use_table) {
        $this->logger->log("Loggia Availability: writing to table {$this->table}");
        $this->write_to_table($entries, $post_id, (string)$loggia_property_id, $from, $to);
      } else {
        $this->logger->log("Loggia Availability: table missing; writing to post meta instead.");
        $this->write_to_meta($entries, $post_id, $from, $to);
      }

      $this->logger->log("Loggia Availability: sync complete for {$loggia_property_id} {$from}→{$to}");
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
    private function table_exists_and_has_min_columns(): bool {
        static $result = null;
        if ($result !== null) return $result;
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table));
        if ($exists !== $this->table) return ($result = false);
        $needed = ['post_id','property_uid','for_date','price','currency','unavailable'];
        $cols = array_column($wpdb->get_results('DESCRIBE '.$this->table, ARRAY_A), 'Field');
        return ($result = empty(array_diff($needed, $cols)));
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
            $ci        = isset($avail['availableForCheckIn'])  ? (int)!empty($avail['availableForCheckIn'])  : null;
            $co        = isset($avail['availableForCheckOut']) ? (int)!empty($avail['availableForCheckOut']) : null;
            $minStay   = isset($avail['minimumStayLength']) ? (int)$avail['minimumStayLength'] : null;
            $maxStay   = isset($avail['maximumStayLength']) ? (int)$avail['maximumStayLength'] : null;

            $wpdb->insert(
                $this->table,
                [
                    'post_id'        => $post_id,
                    'property_uid'   => $propertyUid,
                    'for_date'       => $date,
                    'price'          => $price,
                    'currency'       => $currency,
                    'unavailable'    => $unavail,
                    'checkin'        => $ci,
                    'checkout'       => $co,
                    'min_stay'       => $minStay,
                    'max_stay'       => $maxStay,
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
                'checkin'     => !empty($avail['availableForCheckIn']),
                'checkout'    => !empty($avail['availableForCheckOut']),
                'min_stay'    => isset($avail['minimumStayLength']) ? (int)$avail['minimumStayLength'] : null,
                'max_stay'    => isset($avail['maximumStayLength']) ? (int)$avail['maximumStayLength'] : null,
            ];
        }

        // Store the whole map into one namespaced meta key per year chunk (keeps scale sane).
        // For simplicity now, one key per sync:
        update_post_meta($post_id, '_hcn_availability_map', $map);

        $this->logger->log("Availability: stored ".count($map)." days into post meta for post {$post_id}.");
    }
}