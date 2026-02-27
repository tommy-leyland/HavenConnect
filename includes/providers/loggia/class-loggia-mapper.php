<?php
if ( ! defined('ABSPATH') ) { exit; }

class HavenConnect_Loggia_Mapper {

  public function id_to_queue_item(string $property_id): array {
    return [
      'provider'     => 'loggia',
      'external_id'  => (string) $property_id,

      // Backwards-compat keys
      'uid'          => (string) $property_id,
      'name'         => 'Loggia #' . (string)$property_id,

      // Loggia importer uses id + page_id/locale
      'property_id'  => (string) $property_id,
    ];
  }

  public function ids_to_queue_items(array $ids): array {
    $items = [];
    foreach ($ids as $id) {
      $id = (string)$id;
      if ($id === '') continue;
      $items[] = $this->id_to_queue_item($id);
    }
    return $items;
  }
}