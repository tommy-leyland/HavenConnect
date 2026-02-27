<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Hostfully_Mapper {

  /**
   * Normalize any Hostfully property payload into a queue item.
   * We do NOT guess endpoints here — we just normalize shape.
   */
  public function to_queue_item(array $p): ?array {
    $uid  = $p['uid'] ?? ($p['UID'] ?? null);
    if (!$uid) return null;

    $name = $p['name'] ?? ($p['Name'] ?? 'Untitled');

    return [
      'provider'     => 'hostfully',
      'external_id'  => (string) $uid,

      // Backwards-compat keys (your UI/JS expects these sometimes)
      'uid'          => (string) $uid,
      'name'         => (string) $name,

      // Keep the payload for the existing importer
      'payload'      => $p,
    ];
  }

  /**
   * Map a list of payloads -> queue items
   */
  public function to_queue_items(array $list): array {
    $items = [];
    foreach ($list as $p) {
      if (!is_array($p)) continue;
      $item = $this->to_queue_item($p);
      if ($item) $items[] = $item;
    }
    return $items;
  }
}