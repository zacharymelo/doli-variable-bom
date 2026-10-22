#!/bin/sh
# Regenerate core-patch/full-file/<version>/htdocs/mrp/class/mo.class.php for Dolibarr releases,
# by applying core-patch/apply_hook.php to the file of each release tag of a Dolibarr git clone.
#
# Usage: dev/build_full_files.sh /path/to/dolibarr-git-clone 24.0.0 24.0.1 24.0.2
#
# The clone must have the release tags fetched: git fetch origin 'refs/tags/24.0*:refs/tags/24.0*'
set -e

REPO="$1"
shift
if [ -z "$REPO" ] || [ $# -eq 0 ]; then
	echo "Usage: $0 /path/to/dolibarr-git-clone VERSION [VERSION ...]" >&2
	exit 1
fi

HERE=$(cd "$(dirname "$0")/.." && pwd)
PATCHER="$HERE/core-patch/apply_hook.php"

for VERSION in "$@"; do
	DIR="$HERE/core-patch/full-file/$VERSION/htdocs"
	mkdir -p "$DIR/mrp/class"
	git -C "$REPO" show "$VERSION:htdocs/mrp/class/mo.class.php" > "$DIR/mrp/class/mo.class.php"
	php "$PATCHER" "$DIR"
	rm -f "$DIR/mrp/class/mo.class.php.orig"
	php -l "$DIR/mrp/class/mo.class.php" > /dev/null
	echo "Built core-patch/full-file/$VERSION/htdocs/mrp/class/mo.class.php"
done
echo "Remember to add the new versions to core-patch/full-file/README.md"
