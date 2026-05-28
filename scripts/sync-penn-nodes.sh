#!/usr/bin/env bash
set -euo pipefail

SOURCE="${SOURCE:-$HOME/Desktop/penn-upstream-source}"
DESTINATION="${DESTINATION:-$HOME/Desktop/penn-upstream-destination}"
EXPORT_NAME="${EXPORT_NAME:-penn-node-copy.json}"
SOURCE_EXPORT="$SOURCE/private/$EXPORT_NAME"
DESTINATION_EXPORT="$DESTINATION/private/$EXPORT_NAME"
CONTAINER_EXPORT="/app/private/$EXPORT_NAME"
SOURCE_ASSETS="$SOURCE_EXPORT.assets"
DESTINATION_ASSETS="$DESTINATION_EXPORT.assets"

BUNDLE_OPTION="${BUNDLE_OPTION:-}"
NODES="${NODES:-}"
PUBLISHED_ONLY="${PUBLISHED_ONLY:-0}"
DRY_RUN="${DRY_RUN:-0}"

if [[ $# -gt 0 && -z "$NODES" ]]; then
  NODES="$1"
fi

mkdir -p "$SOURCE/private" "$DESTINATION/private"

export_args=("$CONTAINER_EXPORT")
if [[ -n "$NODES" ]]; then
  export_args+=("$NODES")
fi
if [[ -n "$BUNDLE_OPTION" ]]; then
  export_args+=("--bundle=$BUNDLE_OPTION")
fi
if [[ "$PUBLISHED_ONLY" == "1" ]]; then
  export_args+=("--published-only")
fi

import_args=("$CONTAINER_EXPORT")
if [[ "$DRY_RUN" == "1" ]]; then
  import_args+=("--dry-run")
fi

cd "$SOURCE"
lando drush penn-node-copy:export "${export_args[@]}"

if [[ ! -f "$SOURCE_EXPORT" ]]; then
  echo "Expected export was not found at: $SOURCE_EXPORT" >&2
  echo "The Drush export command used container path: $CONTAINER_EXPORT" >&2
  echo "Try setting EXPORT_NAME to the file you exported, or rerun with a node list, for example:" >&2
  echo "  $0 1,2,3,4" >&2
  exit 1
fi

cp "$SOURCE_EXPORT" "$DESTINATION_EXPORT"
rm -rf "$DESTINATION_ASSETS"
if [[ -d "$SOURCE_ASSETS" ]]; then
  cp -R "$SOURCE_ASSETS" "$DESTINATION_ASSETS"
fi

cd "$DESTINATION"
lando drush penn-node-copy:import "${import_args[@]}"
