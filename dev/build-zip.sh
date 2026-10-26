#!/bin/sh
# Builds the plugin ZIP from a committed tree (never from the working directory):
#
#   sh dev/build-zip.sh [<commit>] [<suffix>]   # e.g. sh dev/build-zip.sh HEAD rc1
#
# Output: dist/tablerefresh-<version>[-<suffix>].zip with a single top-level tablerefresh/ folder.
# Files marked export-ignore in tablerefresh/.gitattributes (coding-standard config) are left out;
# tests, Docker files, fixtures, and tools live outside tablerefresh/ and are never included.
set -eu
cd "$(dirname "$0")/.."
commit="${1:-HEAD}"
suffix="${2:-}"

version=$(git show "$commit:tablerefresh/tablerefresh.php" | sed -n 's/^ \* Version: *//p')
stable=$(git show "$commit:tablerefresh/readme.txt" | sed -n 's/^Stable tag: *//p')
constant=$(git show "$commit:tablerefresh/tablerefresh.php" | sed -n "s/^define( 'TABLEREFRESH_VERSION', '\(.*\)' );/\1/p")
if [ "$version" != "$stable" ] || [ "$version" != "$constant" ]; then
	echo "Version mismatch: header $version, readme stable tag $stable, TABLEREFRESH_VERSION $constant" >&2
	exit 1
fi

mkdir -p dist
zip="dist/tablerefresh-$version${suffix:+-$suffix}.zip"
rm -f "$zip"
git archive --format=zip --prefix=tablerefresh/ -o "$zip" "$commit:tablerefresh"

# Allowlist of shipped paths.
unexpected=$(unzip -Z1 "$zip" | grep -vE '^tablerefresh/((includes/class-[a-z-]+\.php|assets/admin\.(css|js)|languages/tablerefresh\.pot|tablerefresh\.php|uninstall\.php|readme\.txt)|(includes|assets|languages)/)?$' || true)
if [ -n "$unexpected" ]; then
	echo "Unexpected files in $zip:" >&2
	echo "$unexpected" >&2
	exit 1
fi
echo "$zip ($(git rev-parse --short "$commit"), $(unzip -Z1 "$zip" | grep -vc '/$') files)"
unzip -Z1 "$zip"
