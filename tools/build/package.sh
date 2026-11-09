#!/usr/bin/env bash
#
# Build the installable plugin ZIP.
#
# Ships only what WordPress needs. Development-only material (tests, the
# verification harness, docs, legacy Python helpers) is excluded, and the
# script fails if a forbidden path slips in.
#
# Usage: tools/build/package.sh [output-dir]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT_DIR="${1:-$ROOT/dist}"
SLUG="custom-post-image-creator"
VERSION="$(grep -m1 "define( 'CPIC_VERSION'" "$ROOT/custom-post-image-creator.php" | sed -E "s/.*'CPIC_VERSION', '([^']+)'.*/\1/")"
ZIP="$OUT_DIR/${SLUG}-${VERSION}.zip"

if [ -z "$VERSION" ]; then
	echo "Could not read CPIC_VERSION" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"

# Copy the shipped surface only.
for path in custom-post-image-creator.php uninstall.php readme.txt src templates resources fonts languages css js includes; do
	if [ -e "$ROOT/$path" ]; then
		cp -R "$ROOT/$path" "$STAGE/$SLUG/"
	fi
done

# Remove development-only material that lives inside shipped folders.
rm -rf "$STAGE/$SLUG/tests" "$STAGE/$SLUG/tools"
find "$STAGE/$SLUG" -name '__pycache__' -type d -prune -exec rm -rf {} +
find "$STAGE/$SLUG" -name '*.pyc' -delete
find "$STAGE/$SLUG" -name '*.py' -delete
find "$STAGE/$SLUG" -name '.DS_Store' -delete

# Guard rails: nothing below may be in the archive.
if find "$STAGE/$SLUG" -path '*/node_modules/*' -print -quit | grep -q .; then
	echo "node_modules leaked into the build" >&2
	exit 1
fi
if [ -d "$STAGE/$SLUG/tests" ] || [ -d "$STAGE/$SLUG/tools" ]; then
	echo "development folders leaked into the build" >&2
	exit 1
fi
if find "$STAGE/$SLUG" -name '*.py' -print -quit | grep -q .; then
	echo "python files leaked into the build" >&2
	exit 1
fi

# Every relative require/echo path in the plugin must exist in the archive.
missing=0
while read -r rel; do
	[ -z "$rel" ] && continue
	if [ ! -e "$STAGE/$SLUG/$rel" ]; then
		echo "missing referenced file: $rel" >&2
		missing=$((missing + 1))
	fi
done < <(cd "$ROOT" && grep -rhoE "(resources/(js|css)/[A-Za-z0-9_.-]+|fonts/[A-Za-z0-9_.-]+)" src templates custom-post-image-creator.php 2>/dev/null | sort -u)

if [ "$missing" -gt 0 ]; then
	echo "$missing referenced asset(s) are missing from the build" >&2
	exit 1
fi

mkdir -p "$OUT_DIR"
rm -f "$ZIP"

# Deterministic archive: fixed timestamps and sorted entries.
(cd "$STAGE" && find "$SLUG" -type f -print0 | LC_ALL=C sort -z | \
	xargs -0 zip -q -X -9 "$ZIP")

echo "built $ZIP"
echo "files: $(unzip -l "$ZIP" | tail -1 | awk '{print $2}')"
echo "size:  $(du -h "$ZIP" | cut -f1)"
