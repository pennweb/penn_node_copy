<?php

namespace Drupal\penn_node_copy\Commands;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for copying Penn nodes and their embedded dependencies.
 */
class PennNodeCopyCommands extends DrushCommands {

  /**
   * Source UUID to destination entity ID map built during import.
   *
   * @var array<string, array<string, int>>
   */
  private array $entityIdMap = [];

  /**
   * Absolute path to the sidecar asset directory for the current export.
   *
   * @var string|null
   */
  private ?string $exportAssetBasePath = NULL;

  /**
   * Absolute path to the sidecar file asset directory for the current export.
   *
   * @var string|null
   */
  private ?string $exportFileAssetDirectory = NULL;

  /**
   * Directory containing the current import JSON file.
   *
   * @var string|null
   */
  private ?string $importBaseDirectory = NULL;

  /**
   * Export nodes, embedded Penn Entities, media, and files to JSON.
   *
   * @param string $file
   *   Absolute path to write inside the Lando appserver container.
   * @param string $nodes
   *   Optional node ID or comma-separated node IDs to export.
   * @param array $options
   *   Command options.
   *
   * @option bundle
   *   Comma-separated node bundles to export. Defaults to all bundles.
   * @option nid
   *   Comma-separated source node IDs to export.
   * @option uuid
   *   Comma-separated source node UUIDs to export.
   * @option published-only
   *   Export only published nodes.
   *
   * @command penn-node-copy:export
   * @aliases pncex
   */
  public function export(string $file, string $nodes = '', array $options = [
    'bundle' => NULL,
    'nid' => NULL,
    'uuid' => NULL,
    'published-only' => FALSE,
  ]): void {
    $directory = dirname($file);
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new \RuntimeException(sprintf('The export directory is not writable: %s', $directory));
    }

    $this->exportAssetBasePath = $file . '.assets';
    $this->exportFileAssetDirectory = $this->exportAssetBasePath . '/files';
    \Drupal::service('file_system')->prepareDirectory($this->exportFileAssetDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $nodes = $this->loadNodesForExport($options, $nodes);
    $payload = $this->buildEmptyPayload();
    $payload['root_nodes'] = [];

    foreach ($nodes as $node) {
      $payload['root_nodes'][] = $node->uuid();
      $this->exportContentEntityWithDependencies($node, $payload);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE || file_put_contents($file, $json . PHP_EOL) === FALSE) {
      throw new \RuntimeException(sprintf('Unable to write export file: %s', $file));
    }

    $this->logger()->success(sprintf(
      'Exported %d nodes, %d Penn Entities, %d media items, and %d files to %s.',
      count($payload['entities']['node']),
      count($payload['entities']['penn_entity']),
      count($payload['entities']['media']),
      count($payload['entities']['file']),
      $file
    ));
  }

  /**
   * Import a Penn Node Copy JSON export.
   *
   * @param string $file
   *   Absolute path to read inside the Lando appserver container.
   * @param array $options
   *   Command options.
   *
   * @option dry-run
   *   Report creates and updates without saving changes.
   *
   * @command penn-node-copy:import
   * @aliases pncim
   */
  public function import(string $file, array $options = ['dry-run' => FALSE]): void {
    if (!is_readable($file)) {
      throw new \RuntimeException(sprintf('The import file is not readable: %s', $file));
    }

    $payload = json_decode((string) file_get_contents($file), TRUE);
    if (!$this->isValidPayload($payload)) {
      throw new \RuntimeException('The import file is not a valid Penn Node Copy export.');
    }

    $dry_run = (bool) $options['dry-run'];
    $this->entityIdMap = [];
    $this->importBaseDirectory = dirname($file);
    $file_stats = $this->importFiles($payload['entities']['file'] ?? [], $dry_run);
    $media_stats = $this->importContentEntities('media', $payload['entities']['media'] ?? [], $dry_run);
    $penn_entity_stats = $this->importContentEntities('penn_entity', $payload['entities']['penn_entity'] ?? [], $dry_run);
    $node_stats = $this->importContentEntities('node', $payload['entities']['node'] ?? [], $dry_run);

    $prefix = $dry_run ? 'Dry run complete' : 'Import complete';
    $this->logger()->success(sprintf(
      '%s: files %d created, %d reused; media %d created, %d updated, %d unchanged; Penn Entities %d created, %d updated, %d unchanged; nodes %d created, %d updated, %d unchanged; aliases %d synced.',
      $prefix,
      $file_stats['created'],
      $file_stats['reused'],
      $media_stats['created'],
      $media_stats['updated'],
      $media_stats['unchanged'],
      $penn_entity_stats['created'],
      $penn_entity_stats['updated'],
      $penn_entity_stats['unchanged'],
      $node_stats['created'],
      $node_stats['updated'],
      $node_stats['unchanged'],
      $node_stats['aliases']
    ));
  }

  /**
   * Build an empty export payload.
   *
   * @return array
   *   Export payload.
   */
  private function buildEmptyPayload(): array {
    return [
      'format' => 'penn_node_copy',
      'version' => 1,
      'exported_at' => gmdate('c'),
      'entities' => [
        'node' => [],
        'penn_entity' => [],
        'media' => [],
        'file' => [],
      ],
    ];
  }

  /**
   * Validate an import payload.
   *
   * @param mixed $payload
   *   Decoded JSON payload.
   *
   * @return bool
   *   TRUE when the payload is valid.
   */
  private function isValidPayload($payload): bool {
    return is_array($payload)
      && ($payload['format'] ?? NULL) === 'penn_node_copy'
      && isset($payload['entities'])
      && is_array($payload['entities']);
  }

  /**
   * Load source nodes selected by command options.
   *
   * @param array $options
   *   Command options.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded nodes.
   */
  private function loadNodesForExport(array $options, string $node_ids = ''): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = [];

    $argument_nids = $this->parseNodeIdList($node_ids);
    if ($argument_nids) {
      $ids = array_merge($ids, $argument_nids);
    }

    $nids = $this->parseNodeIdList($options['nid'] ?? NULL);
    if ($nids) {
      $ids = array_merge($ids, $nids);
    }

    $uuids = $this->splitOption($options['uuid'] ?? NULL);
    foreach ($uuids as $uuid) {
      $matches = $storage->loadByProperties(['uuid' => $uuid]);
      $node = reset($matches);
      if ($node instanceof NodeInterface) {
        $ids[] = (int) $node->id();
      }
      else {
        $this->logger()->warning(sprintf('Node UUID %s was not found in the source site.', $uuid));
      }
    }

    if (!$ids) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->sort('type', 'ASC')
        ->sort('title', 'ASC');

      $bundles = $this->splitOption($options['bundle'] ?? NULL);
      if ($bundles) {
        $query->condition('type', $bundles, 'IN');
      }
      if (!empty($options['published-only'])) {
        $query->condition('status', NodeInterface::PUBLISHED);
      }

      $ids = $query->execute();
    }

    $nodes = [];
    foreach ($storage->loadMultiple(array_unique($ids)) as $node) {
      if ($node instanceof NodeInterface) {
        $nodes[] = $node;
      }
    }

    return $nodes;
  }

  /**
   * Split a comma-separated option value.
   *
   * @param string|null $value
   *   Option value.
   *
   * @return string[]
   *   Clean values.
   */
  private function splitOption(?string $value): array {
    if ($value === NULL || trim($value) === '') {
      return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
  }

  /**
   * Parse a comma-separated node ID option.
   *
   * @param string|null $value
   *   Node ID list.
   *
   * @return int[]
   *   Parsed node IDs.
   */
  private function parseNodeIdList(?string $value): array {
    $items = $this->splitOption($value);
    $ids = [];

    foreach ($items as $item) {
      if (!ctype_digit($item) || (int) $item <= 0) {
        throw new \InvalidArgumentException(sprintf('Node lists must contain numeric node IDs like "1,2,3". To export a bundle, use --bundle=%s.', $item));
      }
      $ids[] = (int) $item;
    }

    return $ids;
  }

  /**
   * Export a content entity and recursively export supported dependencies.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to export.
   * @param array $payload
   *   Export payload, mutated in place.
   */
  private function exportContentEntityWithDependencies(ContentEntityInterface $entity, array &$payload): void {
    $entity_type_id = $entity->getEntityTypeId();
    if (!isset($payload['entities'][$entity_type_id])) {
      return;
    }

    $uuid = $entity->uuid();
    if (isset($payload['entities'][$entity_type_id][$uuid])) {
      return;
    }

    $record = $this->exportContentEntity($entity);
    $payload['entities'][$entity_type_id][$uuid] = $record;

    foreach ($this->collectDependenciesFromRecord($record) as $dependency) {
      $this->exportDependency($dependency['entity_type'], $dependency['uuid'], $payload);
    }
  }

  /**
   * Export a dependency by entity type and UUID.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $payload
   *   Export payload, mutated in place.
   */
  private function exportDependency(string $entity_type_id, string $uuid, array &$payload): void {
    if ($entity_type_id === 'file') {
      if (!isset($payload['entities']['file'][$uuid])) {
        $file = $this->loadEntityByUuid('file', $uuid);
        if ($file instanceof FileInterface) {
          $payload['entities']['file'][$uuid] = $this->exportFile($file);
        }
        else {
          $this->logger()->warning(sprintf('Referenced file %s was not found in the source site.', $uuid));
        }
      }
      return;
    }

    if (!in_array($entity_type_id, ['penn_entity', 'media'], TRUE)) {
      return;
    }

    $entity = $this->loadEntityByUuid($entity_type_id, $uuid);
    if ($entity instanceof ContentEntityInterface) {
      $this->exportContentEntityWithDependencies($entity, $payload);
    }
    else {
      $this->logger()->warning(sprintf('Referenced %s %s was not found in the source site.', $entity_type_id, $uuid));
    }
  }

  /**
   * Export one content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to export.
   *
   * @return array
   *   Export record.
   */
  private function exportContentEntity(ContentEntityInterface $entity): array {
    $entity_type = $entity->getEntityType();
    $record = [
      'entity_type' => $entity->getEntityTypeId(),
      'uuid' => $entity->uuid(),
      'bundle' => $entity->bundle(),
      'langcode' => $entity->language()->getId(),
      'label' => $entity->label(),
      'fields' => [],
    ];

    if ($entity instanceof NodeInterface) {
      $record['path_aliases'] = $this->getPathAliases('/node/' . $entity->id());
    }

    foreach ($entity->getFields() as $field_name => $field) {
      $definition = $field->getFieldDefinition();
      if ($definition->isComputed() || $definition->isReadOnly() || $this->isSkippedField($entity, $field_name)) {
        continue;
      }

      $field_type = $definition->getType();
      $field_record = [
        'name' => $field_name,
        'type' => $field_type,
        'values' => $field->getValue(),
      ];

      if (in_array($field_type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        $field_record['target_type'] = $definition->getSetting('target_type');
        $field_record['values'] = $this->exportEntityReferenceField($field);
      }
      elseif (in_array($field_type, ['file', 'image'], TRUE)) {
        $field_record['target_type'] = 'file';
        $field_record['values'] = $this->exportFileField($field);
      }

      $record['fields'][$field_name] = $field_record;
    }

    $bundle_key = $entity_type->getKey('bundle');
    if ($bundle_key) {
      $record['bundle_key'] = $bundle_key;
    }

    return $record;
  }

  /**
   * Determine whether a field should be skipped in export/import.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   * @param string $field_name
   *   Field name.
   *
   * @return bool
   *   TRUE if skipped.
   */
  private function isSkippedField(ContentEntityInterface $entity, string $field_name): bool {
    $entity_type = $entity->getEntityType();
    $keys = array_filter([
      $entity_type->getKey('id'),
      $entity_type->getKey('revision'),
      $entity_type->getKey('bundle'),
      $entity_type->getKey('uuid'),
    ]);

    $revision_fields = [
      'default_langcode',
      'content_translation_source',
      'content_translation_outdated',
      'content_translation_uid',
      'content_translation_created',
      'path',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'revision_default',
      'revision_translation_affected',
    ];

    return in_array($field_name, array_merge($keys, $revision_fields), TRUE);
  }

  /**
   * Export entity-reference field values using UUIDs.
   *
   * @param mixed $field
   *   Field item list.
   *
   * @return array
   *   Exported values.
   */
  private function exportEntityReferenceField($field): array {
    $values = [];

    foreach ($field as $item) {
      $value = $item->getValue();
      $target = $item->entity;
      unset($value['target_id']);
      $value['target_uuid'] = $target instanceof EntityInterface && $target->uuid() ? $target->uuid() : NULL;
      $values[] = $value;
    }

    return $values;
  }

  /**
   * Export file/image field values using file UUIDs.
   *
   * @param mixed $field
   *   Field item list.
   *
   * @return array
   *   Exported values.
   */
  private function exportFileField($field): array {
    $values = [];

    foreach ($field as $item) {
      $value = $item->getValue();
      $target = $item->entity;
      unset($value['target_id']);
      $value['target_uuid'] = $target instanceof EntityInterface && $target->uuid() ? $target->uuid() : NULL;
      $values[] = $value;
    }

    return $values;
  }

  /**
   * Collect dependencies referenced by an exported record.
   *
   * @param array $record
   *   Exported content entity record.
   *
   * @return array
   *   Dependency records with entity_type and uuid.
   */
  private function collectDependenciesFromRecord(array $record): array {
    $dependencies = [];

    foreach ($record['fields'] ?? [] as $field_record) {
      $field_type = $field_record['type'] ?? NULL;
      $target_type = $field_record['target_type'] ?? NULL;

      if (in_array($field_type, ['entity_reference', 'entity_reference_revisions'], TRUE) && in_array($target_type, ['penn_entity', 'media', 'file'], TRUE)) {
        foreach ($field_record['values'] ?? [] as $value) {
          if (!empty($value['target_uuid'])) {
            $dependencies[] = ['entity_type' => $target_type, 'uuid' => $value['target_uuid']];
          }
        }
      }

      if (in_array($field_type, ['file', 'image'], TRUE)) {
        foreach ($field_record['values'] ?? [] as $value) {
          if (!empty($value['target_uuid'])) {
            $dependencies[] = ['entity_type' => 'file', 'uuid' => $value['target_uuid']];
          }
        }
      }

      foreach ($this->extractEmbeddedDependencies($field_record['values'] ?? []) as $embedded_dependency) {
        $dependencies[] = $embedded_dependency;
      }
    }

    $unique = [];
    foreach ($dependencies as $dependency) {
      $key = $dependency['entity_type'] . ':' . $dependency['uuid'];
      $unique[$key] = $dependency;
    }

    return array_values($unique);
  }

  /**
   * Extract embedded entity dependencies from arbitrary field values.
   *
   * @param mixed $values
   *   Field values.
   *
   * @return array
   *   Dependency records.
   */
  private function extractEmbeddedDependencies($values): array {
    $dependencies = [];

    foreach ($this->flattenStrings($values) as $text) {
      if (strpos($text, 'drupal-entity') === FALSE) {
        continue;
      }

      preg_match_all('/<drupal-entity\b[^>]*>/i', $text, $matches);
      foreach ($matches[0] ?? [] as $tag) {
        $entity_type = $this->extractAttribute($tag, 'data-entity-type');
        $uuid = $this->extractAttribute($tag, 'data-entity-uuid');
        if ($uuid && in_array($entity_type, ['penn_entity', 'media', 'file'], TRUE)) {
          $dependencies[] = ['entity_type' => $entity_type, 'uuid' => $uuid];
        }
      }
    }

    return $dependencies;
  }

  /**
   * Flatten all scalar strings inside a value.
   *
   * @param mixed $value
   *   Value to inspect.
   *
   * @return string[]
   *   Strings.
   */
  private function flattenStrings($value): array {
    if (is_string($value)) {
      return [$value];
    }
    if (!is_array($value)) {
      return [];
    }

    $strings = [];
    foreach ($value as $child) {
      $strings = array_merge($strings, $this->flattenStrings($child));
    }

    return $strings;
  }

  /**
   * Extract an HTML attribute from a tag string.
   *
   * @param string $tag
   *   HTML tag.
   * @param string $attribute
   *   Attribute name.
   *
   * @return string|null
   *   Attribute value.
   */
  private function extractAttribute(string $tag, string $attribute): ?string {
    if (!preg_match('/\s' . preg_quote($attribute, '/') . '\s*=\s*([\'"])(.*?)\1/i', $tag, $match)) {
      return NULL;
    }

    return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /**
   * Export a managed file and its bytes.
   *
   * @param \Drupal\file\FileInterface $file
   *   File entity.
   *
   * @return array
   *   Export record.
   */
  private function exportFile(FileInterface $file): array {
    $uri = $file->getFileUri();
    $realpath = \Drupal::service('file_system')->realpath($uri);
    $asset_path = NULL;

    if ($realpath && is_file($realpath)) {
      if (!$this->exportAssetBasePath || !$this->exportFileAssetDirectory) {
        throw new \RuntimeException('The export asset directory was not initialized.');
      }

      $asset_path = basename($this->exportAssetBasePath) . '/files/' . $file->uuid();
      $asset_realpath = $this->exportFileAssetDirectory . '/' . $file->uuid();
      if (!copy($realpath, $asset_realpath)) {
        throw new \RuntimeException(sprintf('Unable to copy file bytes from %s to %s.', $realpath, $asset_realpath));
      }
    }
    else {
      $this->logger()->warning(sprintf('File bytes were not found for %s at %s.', $uri, $realpath ?: 'unknown realpath'));
    }

    return [
      'entity_type' => 'file',
      'uuid' => $file->uuid(),
      'uid' => (int) $file->getOwnerId(),
      'filename' => $file->getFilename(),
      'uri' => $uri,
      'filemime' => $file->getMimeType(),
      'filesize' => (int) $file->getSize(),
      'status' => (int) $file->isPermanent(),
      'created' => (int) $file->getCreatedTime(),
      'changed' => (int) $file->getChangedTime(),
      'asset_path' => $asset_path,
      'data' => NULL,
    ];
  }

  /**
   * Import file records.
   *
   * @param array $records
   *   File records keyed by UUID.
   * @param bool $dry_run
   *   Whether to skip writes.
   *
   * @return array
   *   Import stats.
   */
  private function importFiles(array $records, bool $dry_run): array {
    $stats = ['created' => 0, 'reused' => 0];

    foreach ($records as $record) {
      $existing = $this->loadEntityByUuid('file', (string) $record['uuid']);
      if (!$existing) {
        $existing = reset(\Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $record['uri']]));
      }

      if ($existing instanceof FileInterface) {
        $this->entityIdMap['file'][(string) $record['uuid']] = (int) $existing->id();
        $stats['reused']++;
        if (!$dry_run) {
          $this->ensureFileBytes($record);
        }
        continue;
      }

      $stats['created']++;
      if ($dry_run) {
        continue;
      }

      $this->ensureFileBytes($record);
      $file = \Drupal::entityTypeManager()->getStorage('file')->create([
        'uuid' => $record['uuid'],
        'uid' => (int) ($record['uid'] ?? 1),
        'filename' => $record['filename'],
        'uri' => $record['uri'],
        'filemime' => $record['filemime'],
        'filesize' => (int) ($record['filesize'] ?? 0),
        'status' => (bool) ($record['status'] ?? 1),
        'created' => (int) ($record['created'] ?? \Drupal::time()->getRequestTime()),
        'changed' => (int) ($record['changed'] ?? \Drupal::time()->getRequestTime()),
      ]);
      $file->save();
      $this->entityIdMap['file'][(string) $record['uuid']] = (int) $file->id();
    }

    return $stats;
  }

  /**
   * Write exported file bytes to the destination filesystem if needed.
   *
   * @param array $record
   *   File export record.
   */
  private function ensureFileBytes(array $record): void {
    if (empty($record['uri'])) {
      return;
    }

    $file_system = \Drupal::service('file_system');
    $directory = dirname($record['uri']);
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $realpath = $file_system->realpath($record['uri']);
    if ($realpath && is_file($realpath) && (int) filesize($realpath) === (int) ($record['filesize'] ?? -1)) {
      return;
    }

    if (!empty($record['asset_path'])) {
      $source_path = ($this->importBaseDirectory ?: '.') . '/' . $record['asset_path'];
      if (!is_file($source_path)) {
        throw new \RuntimeException(sprintf('Unable to find exported file asset %s for %s.', $source_path, $record['uri']));
      }
      $bytes = file_get_contents($source_path);
      if ($bytes === FALSE) {
        throw new \RuntimeException(sprintf('Unable to read exported file asset %s.', $source_path));
      }
    }
    elseif (!empty($record['data'])) {
      $bytes = base64_decode((string) $record['data'], TRUE);
      if ($bytes === FALSE) {
        throw new \RuntimeException(sprintf('Unable to decode file data for %s.', $record['uri']));
      }
    }
    else {
      return;
    }

    if (file_put_contents($record['uri'], $bytes) === FALSE) {
      throw new \RuntimeException(sprintf('Unable to write file bytes to %s.', $record['uri']));
    }
  }

  /**
   * Import content entity records for one entity type.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param array $records
   *   Export records keyed by UUID.
   * @param bool $dry_run
   *   Whether to skip writes.
   *
   * @return array
   *   Import stats.
   */
  private function importContentEntities(string $entity_type_id, array $records, bool $dry_run): array {
    $stats = [
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'aliases' => 0,
    ];

    if (!$records) {
      return $stats;
    }

    $created = [];
    foreach ($records as $record) {
      $entity = $this->loadEntityByUuid($entity_type_id, (string) $record['uuid']);
      if ($entity instanceof ContentEntityInterface) {
        $this->entityIdMap[$entity_type_id][(string) $record['uuid']] = (int) $entity->id();
        if ($entity->bundle() !== ($record['bundle'] ?? $entity->bundle())) {
          throw new \RuntimeException(sprintf('%s UUID %s exists as bundle %s, not %s.', $entity_type_id, $record['uuid'], $entity->bundle(), $record['bundle']));
        }
        continue;
      }

      $stats['created']++;
      $created[(string) $record['uuid']] = TRUE;
      if ($dry_run) {
        continue;
      }

      $this->createEntityStub($entity_type_id, $record);
    }

    foreach ($records as $record) {
      $entity = $this->loadEntityByUuid($entity_type_id, (string) $record['uuid']);
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      $this->entityIdMap[$entity_type_id][(string) $record['uuid']] = (int) $entity->id();

      $before = $this->fingerprintContentEntity($entity);
      $applied = $this->applyContentEntityRecord($entity, $record);
      $after = $this->fingerprintContentEntity($entity);

      if (!$applied || $before === $after) {
        if (!isset($created[(string) $record['uuid']])) {
          $stats['unchanged']++;
        }
      }
      elseif (!isset($created[(string) $record['uuid']])) {
        $stats['updated']++;
      }

      if (!$dry_run && $applied && ($before !== $after || isset($created[(string) $record['uuid']]))) {
        $entity->save();
      }

      if (!$dry_run && $entity instanceof NodeInterface) {
        $stats['aliases'] += $this->syncPathAliases($entity, $record['path_aliases'] ?? [], FALSE);
      }
    }

    return $stats;
  }

  /**
   * Create a minimal entity stub so UUID references can resolve.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param array $record
   *   Export record.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Created entity.
   */
  private function createEntityStub(string $entity_type_id, array $record): ContentEntityInterface {
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    $values = [
      $entity_type->getKey('uuid') => $record['uuid'],
      'langcode' => $record['langcode'] ?? 'en',
    ];

    $bundle_key = $entity_type->getKey('bundle');
    if ($bundle_key) {
      $values[$bundle_key] = $record['bundle'];
    }

    $label_key = $entity_type->getKey('label');
    if ($label_key && !empty($record['label'])) {
      $values[$label_key] = $record['label'];
    }

    $entity = $storage->create($values);
    if (!$entity instanceof ContentEntityInterface) {
      throw new \RuntimeException(sprintf('Unable to create %s stub for %s.', $entity_type_id, $record['uuid']));
    }
    $entity->save();
    $this->entityIdMap[$entity_type_id][(string) $record['uuid']] = (int) $entity->id();

    return $entity;
  }

  /**
   * Apply exported field values to a destination entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Destination entity.
   * @param array $record
   *   Export record.
   *
   * @return bool
   *   TRUE when field application completed.
   */
  private function applyContentEntityRecord(ContentEntityInterface $entity, array $record): bool {
    foreach ($record['fields'] ?? [] as $field_name => $field_record) {
      if (!$entity->hasField($field_name)) {
        $this->logger()->warning(sprintf('Skipped missing field %s on %s bundle %s.', $field_name, $entity->getEntityTypeId(), $entity->bundle()));
        continue;
      }

      $resolved = $this->resolveFieldValues($field_record, $entity->uuid(), $field_name);
      if (!empty($resolved['skip'])) {
        continue;
      }

      $entity->set($field_name, $resolved['values']);
    }

    if ($entity instanceof NodeInterface && !$entity->isNew()) {
      $entity->setNewRevision(TRUE);
      $entity->setRevisionLogMessage('Updated by Penn Node Copy.');
    }

    return TRUE;
  }

  /**
   * Resolve exported target UUIDs into destination target IDs.
   *
   * @param array $field_record
   *   Exported field record.
   * @param string $owner_uuid
   *   Owner UUID for logging.
   * @param string $field_name
   *   Field name for logging.
   *
   * @return array
   *   Values and skip flag.
   */
  private function resolveFieldValues(array $field_record, string $owner_uuid, string $field_name): array {
    $field_type = $field_record['type'] ?? NULL;
    $target_type = $field_record['target_type'] ?? NULL;

    if (!in_array($field_type, ['entity_reference', 'entity_reference_revisions', 'file', 'image'], TRUE)) {
      return ['values' => $field_record['values'] ?? [], 'skip' => FALSE];
    }

    if (!$target_type) {
      return ['values' => [], 'skip' => FALSE];
    }

    $values = [];
    foreach ($field_record['values'] ?? [] as $value) {
      if (empty($value['target_uuid'])) {
        continue;
      }

      $target_uuid = (string) $value['target_uuid'];
      if (isset($this->entityIdMap[$target_type][$target_uuid])) {
        unset($value['target_uuid']);
        $value['target_id'] = $this->entityIdMap[$target_type][$target_uuid];
        $values[] = $value;
        continue;
      }

      $target = $this->loadEntityByUuid($target_type, $target_uuid);
      if (!$target instanceof EntityInterface) {
        $this->logger()->warning(sprintf(
          'Skipped field %s on %s because referenced %s %s does not exist in the destination.',
          $field_name,
          $owner_uuid,
          $target_type,
          $target_uuid
        ));
        return ['values' => [], 'skip' => TRUE];
      }

      unset($value['target_uuid']);
      $value['target_id'] = $target->id();
      $values[] = $value;
    }

    return ['values' => $values, 'skip' => FALSE];
  }

  /**
   * Create a stable comparison payload for a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to fingerprint.
   *
   * @return array
   *   Comparable field data.
   */
  private function fingerprintContentEntity(ContentEntityInterface $entity): array {
    return $this->exportContentEntity($entity)['fields'];
  }

  /**
   * Load an entity by UUID.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Loaded entity or NULL.
   */
  private function loadEntityByUuid(string $entity_type_id, string $uuid): ?EntityInterface {
    $matches = \Drupal::entityTypeManager()->getStorage($entity_type_id)->loadByProperties(['uuid' => $uuid]);
    $entity = reset($matches);
    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  /**
   * Get path aliases for a system path.
   *
   * @param string $path
   *   System path.
   *
   * @return array
   *   Alias records.
   */
  private function getPathAliases(string $path): array {
    $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', $path)
      ->execute();
    $aliases = [];

    foreach ($storage->loadMultiple($ids) as $alias) {
      $aliases[] = [
        'alias' => $alias->get('alias')->value,
        'langcode' => $alias->language()->getId(),
      ];
    }

    return $aliases;
  }

  /**
   * Sync exported path aliases onto a saved node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Destination node.
   * @param array $aliases
   *   Exported aliases.
   * @param bool $dry_run
   *   Whether writes should be skipped.
   *
   * @return int
   *   Number of aliases changed.
   */
  private function syncPathAliases(NodeInterface $node, array $aliases, bool $dry_run): int {
    if (!$aliases) {
      return 0;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
    $path = '/node/' . $node->id();
    $changed = 0;

    foreach ($aliases as $alias_record) {
      $alias = $alias_record['alias'] ?? NULL;
      if (!$alias) {
        continue;
      }

      $langcode = $alias_record['langcode'] ?? $node->language()->getId();
      $conflicts = $storage->loadByProperties([
        'alias' => $alias,
        'langcode' => $langcode,
      ]);

      foreach ($conflicts as $conflict) {
        if ($conflict->get('path')->value !== $path) {
          $this->logger()->warning(sprintf('Skipped alias %s for %s because it already points to %s.', $alias, $path, $conflict->get('path')->value));
          continue 2;
        }
      }

      $existing = $storage->loadByProperties([
        'path' => $path,
        'langcode' => $langcode,
      ]);
      $path_alias = reset($existing);

      if ($path_alias && $path_alias->get('alias')->value === $alias) {
        continue;
      }

      $changed++;
      if ($dry_run) {
        continue;
      }

      if (!$path_alias) {
        $path_alias = $storage->create([
          'path' => $path,
          'langcode' => $langcode,
        ]);
      }

      $path_alias->set('alias', $alias);
      $path_alias->save();
    }

    return $changed;
  }

}
