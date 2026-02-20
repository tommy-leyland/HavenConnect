<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect_Availability_Importer
 *
 * Stores Hostfully daily availability + pricing + rules
 * into the custom table wp_<prefix>_hcn_availability.
 */
class HavenConnect_Availability_Importer {

    private $api;
    private $logger;
    private $table;

    public function __construct($api, $logger) {
        global $wpdb;
        $this->api    = $api;
        $this->logger = $logger;
        $this->table  = $wpdb->prefix . "hcn_availability";
    }

    /**
     * Import daily rows from Hostfully for a single property.
     *
     * @param string $apiKey        Hostfully API key
     * @param string $property_uid  Hostfully property UID
     */
    public function sync_property_calendar(string $apiKey, string $property_uid)
	{
		global $wpdb;

		$this->logger->log("Availability: Fetching calendar for $property_uid");

		$payload = $this->api->get_property_calendar($apiKey, $property_uid);

		if (empty($payload['calendar'])) {
			$this->logger->log("Availability: No calendar data for $property_uid");
			return;
		}

		// Clear existing future rows
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE property_uid = %s AND date >= CURDATE()",
				$property_uid
			)
		);

		foreach ($payload['calendar'] as $day) {

			if (empty($day['date'])) continue;

			$wpdb->insert(
				$this->table,
				[
					'property_uid'        => $property_uid,
					'date'                => $day['date'],
					'is_available'        => $day['available'] ? 1 : 0,
					'price'               => $day['price'],
					'min_stay'            => $day['minStay'],
					'max_stay'            => $day['maxStay'],
					'closed_to_arrival'   => $day['closedToArrival'] ? 1 : 0,
					'closed_to_departure' => $day['closedToDeparture'] ? 1 : 0,
				],
				['%s','%s','%d','%f','%d','%d','%d','%d']
			);

			$this->logger->log("Calendar row for $property_uid: " . print_r($day, true));
		}

		$this->logger->log("Availability: Synced calendar for $property_uid");
	}
}