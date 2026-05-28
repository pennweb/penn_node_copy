# Penn Node Copy

Custom Drush commands for copying Drupal nodes from one Drupal site to another.

The exporter walks node formatted-text fields for `<drupal-entity>` embeds, follows nested `penn_entity` references, collects media dependencies, and stores managed file bytes in the export JSON. The importer recreates files first, then media, Penn Entities, and nodes.

## Install

Clone the module repository:

```bash
cd ~/Desktop
git clone git@github.com:pennweb/penn_node_copy.git
```

Copy the cloned module to both Drupal sites:

```bash
cp -R ~/Desktop/penn_node_copy ~/Desktop/source/web/modules/custom/
cp -R ~/Desktop/penn_node_copy ~/Desktop/destination/web/modules/custom/
```

Enable it in both Lando apps:

```bash
cd ~/Desktop/source && lando drush en penn_node_copy -y
cd ~/Desktop/destination && lando drush en penn_node_copy -y
```

## Copy Content

Export from source:

> [!CAUTION]
> Danger Zone: exporting a broad bundle such as `page,penn_update` can create a large transfer package and the later import will update matching destination content by UUID. Start with one node or a short node list, run the destination import with `--dry-run`, and confirm the reported changes before running the real import.

```bash
cd ~/Desktop/source
lando drush penn-node-copy:export /app/private/penn-node-copy.json --bundle=page,penn_update
```

Export one node or a comma-separated list:

```bash
lando drush penn-node-copy:export /app/private/penn-node-copy.json 123
lando drush penn-node-copy:export /app/private/penn-node-copy.json 1,2,3,4
```

Copy the export to the destination project:

```bash
cp ~/Desktop/source/private/penn-node-copy.json ~/Desktop/destination/private/penn-node-copy.json
rm -rf ~/Desktop/destination/private/penn-node-copy.json.assets
cp -R ~/Desktop/source/private/penn-node-copy.json.assets ~/Desktop/destination/private/penn-node-copy.json.assets
```

Dry-run the import:

```bash
cd ~/Desktop/destination
lando drush penn-node-copy:import /app/private/penn-node-copy.json --dry-run
```

Import:

```bash
cd ~/Desktop/destination
lando drush penn-node-copy:import /app/private/penn-node-copy.json
```

Useful export filters:

```bash
lando drush penn-node-copy:export /app/private/penn-node-copy.json 123
lando drush penn-node-copy:export /app/private/penn-node-copy.json 1,2,3,4
lando drush penn-node-copy:export /app/private/pages.json --bundle=page --published-only
lando drush penn-node-copy:export /app/private/specific.json --uuid=uuid-1,uuid-2
```

To use a different filename, keep the same filename for export, copy, and import.

Exports with media include a sidecar directory next to the JSON, for example `penn-node-copy.json.assets`. Copy that directory with the JSON before importing.

The helper script defaults to `~/Desktop/source` and `~/Desktop/destination`. Override those paths with `SOURCE_DIR` and `DESTINATION_DIR`. It accepts the same node list through `NODES`:

```bash
SOURCE_DIR=~/Desktop/source DESTINATION_DIR=~/Desktop/destination NODES=1,2,3,4 DRY_RUN=1 ./scripts/sync-penn-nodes.sh
```

Or as the first script argument:

```bash
SOURCE_DIR=~/Desktop/source DESTINATION_DIR=~/Desktop/destination DRY_RUN=1 ./scripts/sync-penn-nodes.sh 1,2,3,4
```
