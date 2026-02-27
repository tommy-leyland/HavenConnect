<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Loggia_Provider {

  /** @var HavenConnect_Logger */
  private $logger;

  /** @var HavenConnect_Photo_Sync */
  private $photos;

  /** @var HavenConnect_Loggia_Importer */
  private $importer;

  /** @var HavenConnect_Loggia_Mapper */
  private $mapper;

  public function __construct($logger, $photos, $loggia_importer) {
    $this->logger   = $logger;
    $this->photos   = $photos;
    $this->importer = $loggia_importer;
    $this->mapper   = new HavenConnect_Loggia_Mapper();
  }

  public function get_key(): string {
    return 'loggia';
  }

  public function get_config(): array {
    $opts = get_option('hcn_loggia', []);
    if (!is_array($opts)) $opts = [];

    // Legacy fallback (only if new option missing)
    $needs = empty($opts['loggia_base_url']) || empty($opts['loggia_api_key']) || empty($opts['loggia_page_id']);
    if ($needs) {
      $legacy = get_option(HavenConnect_Admin::OPTION_KEY, []);
      if (is_array($legacy)) {
        foreach (['loggia_base_url','loggia_api_key','loggia_page_id','loggia_locale'] as $k) {
          if (empty($opts[$k]) && !empty($legacy[$k])) $opts[$k] = $legacy[$k];
        }
      }
    }

    return [
      'base_url' => rtrim((string)($opts['loggia_base_url'] ?? ''), '/'),
      'api_key'  => trim((string)($opts['loggia_api_key'] ?? '')),
      'page_id'  => trim((string)($opts['loggia_page_id'] ?? '')),
      'locale'   => trim((string)($opts['loggia_locale'] ?? 'en')),
    ];
  }

  public function is_configured(): bool {
    $c = $this->get_config();
    return !empty($c['base_url']) && !empty($c['api_key']) && !empty($c['page_id']);
  }

  public function make_client(): ?HavenConnect_Loggia_Client {
    $c = $this->get_config();
    if (!$this->is_configured()) return null;
    return new HavenConnect_Loggia_Client($c['base_url'], $c['api_key'], $this->logger);
  }

  /**
   * Build queue items for Loggia.
   * $limit: optional cap (first N)
   */
  public function build_queue(int $limit = 0): array {
    if (!$this->is_configured()) {
      $this->logger->log('Loggia: missing credentials; cannot build queue.');
      return [];
    }

    $c = $this->get_config();
    $client = $this->make_client();
    if (!$client) return [];

    $ids = $this->importer->list_property_ids($client, $c['page_id'], $c['locale']);
    if (!is_array($ids) || empty($ids)) {
      $this->logger->log('Loggia: list_property_ids returned 0.');
      return [];
    }

    if ($limit > 0) {
      $ids = array_slice($ids, 0, $limit);
    }

    $items = $this->mapper->ids_to_queue_items($ids);
    $this->logger->log('Loggia: queued ' . count($items) . ' items.');
    return $items;
  }

  /**
   * Import one queue item.
   * Returns post_id.
   */
  public function import_one(array $item): int {
    $c = $this->get_config();
    $client = $this->make_client();
    if (!$client) {
      $this->logger->log('Loggia: cannot import (client not configured).');
      return 0;
    }

    $property_id = (string)($item['property_id'] ?? $item['external_id'] ?? '');
    if ($property_id === '') {
      $this->logger->log('Loggia: missing property_id; cannot import.');
      return 0;
    }

    return (int) $this->importer->import_one($client, $property_id, $c['page_id'], $c['locale']);
  }
}