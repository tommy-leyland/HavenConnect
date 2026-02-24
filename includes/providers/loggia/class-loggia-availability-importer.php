<?php
if (!defined('ABSPATH')) exit;

class HavenConnect_Loggia_Availability_Importer {
  private $logger;

  public function __construct($logger) {
    $this->logger = $logger;
  }

  public function import_range(HavenConnect_Loggia_Client $client, string $page_id, string $locale, string $dateFrom, string $dateTo): int {
    global $wpdb;
    $table = $wpdb->prefix . 'hcn_availability';

    $offset = 0;
    $limit  = 50;
    $rows_upserted = 0;

    while (true) {
      $resp = $client->list_properties_v2($page_id, $locale, $dateFrom, $dateTo, $offset, $limit, false);
      if (!is_array($resp) || empty($resp['properties']) || !is_array($resp['properties'])) {
        break;
      }

      foreach ($resp['properties'] as $p) {
        if (!is_array($p)) continue;

        $loggia_id = (string)($p['property_id'] ?? $p['id'] ?? '');
        if ($loggia_id === '') continue;

        // Find WP post_id by meta loggia_property_id
        $post_id = $this->find_post_id_by_loggia_id($loggia_id);
        if (!$post_id) continue;

        $avail = $p['availability'] ?? null;
        if (!is_array($avail)) continue;

        foreach ($avail as $day => $info) {
          if (!is_array($info)) continue;
          $for_date = $info['date'] ?? $day;
          if (!$this->is_date_ymd($for_date)) continue;

          $status = (int)($info['status'] ?? 0);
          $status_desc = (string)($info['status_desc'] ?? '');
          $price = isset($info['price']) && $info['price'] !== '' ? (float)$info['price'] : null;

          // Treat "available" as available only if we have a price
          $is_available = ($status === 59 || strtolower($status_desc) === 'available') && $price !== null;

          $unavailable = $is_available ? 0 : 1;
          $checkin  = isset($info['checkinAllowed']) ? (int)!!$info['checkinAllowed'] : null;
          $checkout = isset($info['checkoutAllowed']) ? (int)!!$info['checkoutAllowed'] : null;
          $min_stay = isset($info['min_stay']) && $info['min_stay'] !== null ? (int)$info['min_stay'] : null;

          // Upsert into canonical table
          $wpdb->query(
            $wpdb->prepare(
              "INSERT INTO {$table}
                (post_id, property_uid, for_date, price, currency, unavailable, checkin, checkout, min_stay, max_stay, updated_at)
               VALUES
                (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s)
               ON DUPLICATE KEY UPDATE
                post_id=VALUES(post_id),
                price=VALUES(price),
                currency=VALUES(currency),
                unavailable=VALUES(unavailable),
                checkin=VALUES(checkin),
                checkout=VALUES(checkout),
                min_stay=VALUES(min_stay),
                updated_at=VALUES(updated_at)",
              $post_id,
              $loggia_id,
              $for_date,
              $price,
              null, // currency not provided by this endpoint
              $unavailable,
              $checkin,
              $checkout,
              $min_stay,
              null, // max_stay unknown
              current_time('mysql')
            )
          );

          $rows_upserted++;
        }
      }

      // Pagination
      $offset += $limit;
      if (count($resp['properties']) < $limit) break;
    }

    if ($this->logger) {
      $this->logger->log("Loggia availability import: upserted {$rows_upserted} rows for {$dateFrom} → {$dateTo}.");
      $this->logger->save();
    }

    return $rows_upserted;
  }

  private function find_post_id_by_loggia_id(string $loggia_id): int {
    $q = new WP_Query([
      'post_type'      => 'hcn_property',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => 1,
      'meta_query'     => [
        [
          'key'   => 'loggia_property_id',
          'value' => $loggia_id,
        ],
      ],
    ]);

    return !empty($q->posts[0]) ? (int)$q->posts[0] : 0;
  }

  private function is_date_ymd(string $s): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
  }
}