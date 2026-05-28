# Penn Node Copy

Custom Drush commands for one-way Drupal content copy/sync from a source Penn upstream site to a destination site.

> [!WARNING]
> Local-only workflow: install this module only in temporary local Lando copies of the source and destination sites. Do not commit or push the copied module, generated export JSON files, or `.assets` directories to either Drupal site repository. Code changes for the tool itself belong only in this standalone `penn_node_copy` repository.

The exporter walks node formatted-text fields for `<drupal-entity>` embeds, follows nested `penn_entity` references, collects media dependencies, and stores managed file bytes in the export JSON. The importer creates missing destination entities and updates matching destination entities by Drupal UUID. It imports files first, then media, Penn Entities, and nodes.

## Recommended Workflow

Use two local Lando projects when testing or running a one-way content copy/sync:

- `penn-upstream-source`: a local copy of the environment you want to copy content from.
- `penn-upstream-destination`: a local copy of the environment you want to update.

In most cases these projects should represent the same Drupal site at two different environments, such as Live and Staging. Refresh each local project with the matching environment database before running the copy/sync commands. That keeps the test close to the real source and destination state, and makes it easier to regression-test the specific `penn-node-copy` command you plan to run.

This is not a two-way sync. Content moves from source to destination only. The tool matches content by Drupal UUID, not by numeric node ID, which is why both local databases should come from related environments of the same site whenever possible.

## Install

Clone the module repository:

```bash
cd ~/Desktop
git clone git@github.com:pennweb/penn_node_copy.git
```

Copy the cloned module to both Drupal sites:

```bash
cp -R ~/Desktop/penn_node_copy ~/Desktop/penn-upstream-source/web/modules/custom/
cp -R ~/Desktop/penn_node_copy ~/Desktop/penn-upstream-destination/web/modules/custom/
```

Enable it in both Lando apps:

```bash
cd ~/Desktop/penn-upstream-source && lando drush en penn_node_copy -y
cd ~/Desktop/penn-upstream-destination && lando drush en penn_node_copy -y
```

## Copy/Sync Content

Export from source:

> [!CAUTION]
> Danger Zone: exporting a broad bundle such as `page,penn_update` can create a large transfer package and the later import will update matching destination content by UUID. Start with one node or a short node list, run the destination import with `--dry-run`, and confirm the reported changes before running the real import.

```bash
cd ~/Desktop/penn-upstream-source
lando drush penn-node-copy:export /app/private/penn-node-copy.json --bundle=page,penn_update
```

> [!TIP]
> Recommended: start with a single node or a short comma-separated node list. This makes the export smaller, easier to inspect, and safer to dry-run before syncing a full bundle.

Export one node or a comma-separated list:

```bash
lando drush penn-node-copy:export /app/private/penn-node-copy.json 123
lando drush penn-node-copy:export /app/private/penn-node-copy.json 1,2,3,4
```

Copy the export to the destination project:

```bash
cp ~/Desktop/penn-upstream-source/private/penn-node-copy.json ~/Desktop/penn-upstream-destination/private/penn-node-copy.json
rm -rf ~/Desktop/penn-upstream-destination/private/penn-node-copy.json.assets
cp -R ~/Desktop/penn-upstream-source/private/penn-node-copy.json.assets ~/Desktop/penn-upstream-destination/private/penn-node-copy.json.assets
```

Dry-run the import:

```bash
cd ~/Desktop/penn-upstream-destination
lando drush penn-node-copy:import /app/private/penn-node-copy.json --dry-run
```

Import:

```bash
cd ~/Desktop/penn-upstream-destination
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

The helper script accepts the same node list through `NODES`:

```bash
NODES=1,2,3,4 DRY_RUN=1 ./scripts/sync-penn-nodes.sh
```

Or as the first script argument:

```bash
DRY_RUN=1 ./scripts/sync-penn-nodes.sh 1,2,3,4
```
