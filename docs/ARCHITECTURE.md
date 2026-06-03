# Architecture: Penn Node Copy

## Overview

Penn Node Copy is a Drupal custom module that provides Drush commands for one-way content copy/sync:

- `penn-node-copy:export`
- `penn-node-copy:import`

The export command runs in the source Drupal site. It writes a JSON manifest and a sidecar asset directory. The import command runs in the destination Drupal site. It reads the manifest and assets, then creates or updates destination entities.

```text
Source Drupal site
  lando drush penn-node-copy:export
        |
        v
  penn-node-copy.json
  penn-node-copy.json.assets/
        |
        v
Destination Drupal site
  lando drush penn-node-copy:import
```

## Module Files

```text
penn_node_copy.info.yml
drush.services.yml
src/Commands/PennNodeCopyCommands.php
scripts/sync-penn-nodes.sh
```

`penn_node_copy.info.yml` declares the Drupal module and dependencies.

`drush.services.yml` registers the Drush command class.

`PennNodeCopyCommands.php` contains the export/import implementation.

`scripts/sync-penn-nodes.sh` is a local helper script that runs export, copies the JSON and assets, and runs import.

## Export Command

Command:

```bash
lando drush penn-node-copy:export /app/private/penn-node-copy.json 123
lando drush penn-node-copy:export /app/private/penn-node-copy.json 1,2,3,4
lando drush penn-node-copy:export /app/private/penn-node-copy.json --bundle=penn_update
```

The exporter can select nodes by:

- positional node ID list
- `--nid`
- `--uuid`
- `--bundle`
- `--published-only`

If no node IDs or UUIDs are provided, it queries nodes by bundle filter, or all nodes when no bundle is supplied.

## Export Payload

The JSON payload has this top-level structure:

```json
{
  "format": "penn_node_copy",
  "version": 1,
  "exported_at": "2026-06-03T00:00:00+00:00",
  "root_nodes": [],
  "entities": {
    "node": {},
    "penn_entity": {},
    "media": {},
    "file": {}
  }
}
```

`root_nodes` contains UUIDs for nodes explicitly selected by the export command.

`entities.node` contains Drupal nodes keyed by UUID. A `penn_update` is represented here as a node with `"bundle": "penn_update"`.

`entities.penn_entity` contains embedded and referenced Penn Entities keyed by UUID.

`entities.media` contains referenced media entities keyed by UUID.

`entities.file` contains managed file entity metadata keyed by UUID.

## Content Entity Records

Content entities are exported with:

- entity type
- UUID
- bundle
- language
- label
- exported fields
- path aliases for nodes

Example:

```json
{
  "entity_type": "node",
  "uuid": "node-uuid",
  "bundle": "page",
  "langcode": "en",
  "label": "Example Page",
  "fields": {},
  "path_aliases": []
}
```

Field handling:

- Computed fields are skipped.
- Read-only fields are skipped.
- ID, revision, bundle, UUID, path, and translation metadata fields are skipped.
- Normal field values are exported using Drupal field item values.
- Entity references replace `target_id` with `target_uuid`.
- File and image fields also replace `target_id` with `target_uuid`.

## Dependency Discovery

Dependency discovery starts with the selected root nodes.

For each exported entity, the exporter inspects fields and collects dependencies from:

- entity reference fields targeting `penn_entity`, `media`, or `file`
- image and file fields
- formatted text containing `<drupal-entity>` tags

Embedded entity tags are parsed for:

```html
data-entity-type
data-entity-uuid
```

Supported embedded dependency types:

- `penn_entity`
- `media`
- `file`

Penn Entity dependencies are recursive. If a node embeds Penn Entity A, and Penn Entity A references Penn Entity B, both entities are exported.

## File Asset Sidecar

File bytes are not stored directly in the JSON. Instead, the exporter writes files into a sidecar directory:

```text
penn-node-copy.json
penn-node-copy.json.assets/
  files/
    {file-uuid}
```

The file entity record stores metadata and an `asset_path`:

```json
{
  "entity_type": "file",
  "uuid": "file-uuid",
  "filename": "example.jpg",
  "uri": "public://example.jpg",
  "filemime": "image/jpeg",
  "filesize": 12345,
  "asset_path": "penn-node-copy.json.assets/files/file-uuid",
  "data": null
}
```

This design keeps large exports manageable. Earlier base64-in-JSON exports could grow too large for bundle-level syncs with many media files.

## Import Command

Command:

```bash
lando drush penn-node-copy:import /app/private/penn-node-copy.json --dry-run
lando drush penn-node-copy:import /app/private/penn-node-copy.json
```

Import order:

1. Files
2. Media
3. Penn Entities
4. Nodes

This order lets references resolve before dependent entities are saved.

## UUID Matching

The importer matches existing destination entities by Drupal UUID.

If a destination entity with the same UUID exists:

- it is updated
- references are resolved to destination entity IDs

If no destination entity with that UUID exists:

- a stub is created first
- full field values are applied in a second pass

This two-pass behavior allows nested Penn Entities to reference each other even when multiple referenced entities are new to the destination.

## File Reuse and Mapping

Files are loaded by UUID first. If no file with the exported UUID exists, the importer checks for an existing file with the same URI.

When an existing file is reused by URI, the importer maps the source file UUID to the destination file ID. This lets media image fields resolve correctly even when file UUIDs differ between environments.

## Dry Run Behavior

`--dry-run` reports expected creates, updates, and unchanged entities without saving changes.

Dry runs are intended as the first review step before a real import, especially for broad bundle exports.

## Current Limitations

- One-way only: source to destination.
- No deletion of destination-only content.
- Best suited for related environments of the same site.
- Assumes destination has compatible field configuration and bundles.
- Only dependencies discovered through supported fields and embedded entity tags are exported.
- Import safety depends on reviewing `--dry-run` output before running a real import.

## Local Helper Script

`scripts/sync-penn-nodes.sh` wraps the local workflow:

1. Export from source.
2. Copy JSON to destination.
3. Copy `.assets` sidecar directory to destination.
4. Import into destination.

Example:

```bash
NODES=1,2,3,4 DRY_RUN=1 ./scripts/sync-penn-nodes.sh
```

The helper script defaults to:

```text
~/Desktop/penn-upstream-source
~/Desktop/penn-upstream-destination
```
