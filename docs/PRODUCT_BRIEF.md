# Product Brief: Penn Node Copy

## Summary

Penn Node Copy is a local-only Drupal utility for one-way content copy/sync between two local Lando copies of related Penn upstream sites. It exports selected content and dependencies from a source site, then imports them into a destination site by creating missing entities and updating matching entities by Drupal UUID.

The tool is intended for controlled local workflows, especially when comparing or moving content between environments of the same site, such as Live to Staging.

## Problem

Penn upstream content is not always contained in a single node. A page or Penn Update may include embedded Penn Entities, nested Penn Entity references, media records, and underlying managed files. Manually moving this content is error-prone because copying only the node body can leave missing components, broken media, or unresolved references.

Teams need a repeatable way to test content movement locally before applying the same workflow with confidence.

## Goals

- Copy selected Drupal nodes from a source local site to a destination local site.
- Include embedded Penn Entities found in formatted text fields.
- Follow nested Penn Entity references recursively.
- Include referenced media and managed file assets.
- Update existing destination content by Drupal UUID.
- Create destination entities that do not already exist.
- Support small targeted tests, such as one node or a comma-separated node list.
- Support broader bundle exports, such as `penn_update`, when needed.
- Keep the tool out of production site repositories.

## Non-Goals

- This is not a two-way sync tool.
- This is not a replacement for full database cloning.
- This is not intended to run directly on production environments.
- This does not delete destination-only content.
- This does not guarantee support for unrelated Drupal sites whose UUIDs and field structures do not line up.

## Primary Users

- Developers working with Penn upstream Drupal sites.
- Site maintainers testing content movement between related environments.
- Technical users validating changes locally before coordinating a controlled content update.

## Recommended Workflow

1. Create or refresh two local Lando projects:
   - `penn-upstream-source`: local copy of the environment content is copied from.
   - `penn-upstream-destination`: local copy of the environment content is copied into.
2. Import the matching source database into the source local project.
3. Import the matching destination database into the destination local project.
4. Install this module in both local projects.
5. Export a small test set first, such as one node or a short node list.
6. Copy the JSON export and `.assets` sidecar directory to the destination project.
7. Run the destination import with `--dry-run`.
8. Review the reported creates and updates.
9. Run the real import only after the dry run matches expectations.

## Success Criteria

- A selected node can be copied from source to destination.
- A changed source node updates the matching destination node by UUID.
- Embedded Penn Entities referenced from node body fields are included.
- Nested Penn Entity references are included.
- Referenced media and files are available after import.
- A full `penn_update` bundle export can complete without exhausting memory.
- Dry-run output gives enough information to review expected changes before import.

## Key Safety Notes

- Run only in local Lando copies.
- Do not commit the copied module into source or destination Drupal site repositories.
- Do not commit generated `.json` exports or `.json.assets` directories.
- Treat broad bundle exports as high-impact operations because import updates matching destination content by UUID.
