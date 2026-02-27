<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Hostfully_Provider {

  /** @var HavenConnect_Api_Client */
  private $api;

  /** @var HavenConnect_Property_Importer */
  private $importer;

  /** @var HavenConnect_Logger */
  private $logger;

  /** @var HavenConnect_Hostfully_Mapper */
  private $mapper;

  public function __construct($api_client, $property_importer, $logger) {
    $this->api      = $api_client;
    $this->importer = $property_importer;
    $this->logger   = $logger;
    $this->mapper   = new HavenConnect_Hostfully_Mapper();
  }

  public function get_key(): string {
    return 'hostfully';
  }

  public function get_config(): array {
    $opts = get_option('hcn_hostfully', []);
    if (!is_array($opts)) $opts = [];

    // Legacy fallback (only if new option missing)
    if (empty($opts['api_key']) || empty($opts['agency_uid'])) {
      $legacy = get_option(HavenConnect_Admin::OPTION_KEY, []);
      if (is_array($legacy)) {
        if (empty($opts['api_key']) && !empty($legacy['api_key'])) $opts['api_key'] = $legacy['api_key'];
        if (empty($opts['agency_uid']) && !empty($legacy['agency_uid'])) $opts['agency_uid'] = $legacy['agency_uid'];
      }
    }

    return [
      'api_key'    => trim((string)($opts['api_key'] ?? '')),
      'agency_uid' => trim((string)($opts['agency_uid'] ?? '')),
    ];
  }

  public function is_configured(): bool {
    $c = $this->get_config();
    return !empty($c['api_key']) && !empty($c['agency_uid']);
  }

  /**
   * Build queue items for Hostfully.
   *
   * $mode: all|featured
   * $single_uid: optional (single property test)
   * $limit: optional integer limit
   */
  public function build_queue(string $mode = 'all', string $single_uid = '', int $limit = 0): array {
    $c = $this->get_config();
    $apiKey = $c['api_key'];
    $agency = $c['agency_uid'];

    if (!$apiKey || !$agency) {
      $this->logger->log('Hostfully: missing credentials; cannot build queue.');
      return [];
    }

    $list = [];
    $source = 'all';

    if ($single_uid) {
      // Uses existing API client method (no guessing endpoints here)
      $details = $this->api->get_property_details($apiKey, $single_uid);
      $p = [];
      if (is_array($details)) {
        $p = (isset($details['property']) && is_array($details['property'])) ? $details['property'] : $details;
      }
      if ($p) {
        $list = [$p];
        $source = 'single';
      }
    } else {
      if ($mode === 'featured' && method_exists($this->api, 'get_featured_list')) {
        $list = $this->api->get_featured_list($apiKey, $agency);
        $source = 'featured';
      } else {
        if (method_exists($this->api, 'get_properties_by_agency')) {
          $list = $this->api->get_properties_by_agency($apiKey, $agency);
          $source = 'all';
        }
      }
    }

    if (!is_array($list) || empty($list)) {
      $this->logger->log("Hostfully: queue build returned 0 items (source={$source}).");
      return [];
    }

    $items = $this->mapper->to_queue_items($list);

    if ($limit > 0) {
      $items = array_slice($items, 0, $limit);
    }

    $this->logger->log("Hostfully: queued " . count($items) . " items (source={$source}).");
    return $items;
  }

  /**
   * Import one queue item.
   * Returns post_id.
   */
  public function import_one(array $item): int {
    $c = $this->get_config();
    $apiKey = $c['api_key'];
    if (!$apiKey) {
      $this->logger->log('Hostfully: missing api_key; cannot import.');
      return 0;
    }

    $payload = $item['payload'] ?? null;
    if (!is_array($payload)) {
      $this->logger->log('Hostfully: missing payload; cannot import.');
      return 0;
    }

    // Reuse your existing importer path (no endpoint changes)
    return (int) $this->importer->import_property_from_featured($apiKey, $payload);
  }
}