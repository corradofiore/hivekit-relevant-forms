#!/usr/bin/env bash
#
# Build the WordPress.org release artifact: runtime files only.
#
# The release zip contains no tests, dev tooling or vendored dependencies:
# the plugin autoloader falls back to a plain PSR-4 loader when vendor/ is
# absent (see relevant-forms.php).
#
# Usage: composer build:release   (or: bash bin/build-release.sh)

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' relevant-forms.php | head -n 1)"

if [ -z "$VERSION" ]; then
	echo "Could not read the plugin version from relevant-forms.php" >&2
	exit 1
fi

DIST="dist"
STAGE="$DIST/relevant-forms"

rm -rf "$DIST"
mkdir -p "$STAGE"

rsync -a --delete \
	--exclude 'dist/' \
	--exclude 'tests/' \
	--exclude 'vendor/' \
	--exclude 'bin/' \
	--exclude 'node_modules/' \
	--exclude '.git/' \
	--exclude '.phpunit.cache/' \
	--exclude '.wp-env.json' \
	--exclude '.wp-env.test.json' \
	--exclude '.wp-env.override.json' \
	--exclude '.editorconfig' \
	--exclude '.gitignore' \
	--exclude '.DS_Store' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml.dist' \
	--exclude 'phpstan.neon.dist' \
	--exclude 'phpunit.xml.dist' \
	--exclude 'phpunit.integration.xml.dist' \
	--exclude 'storage/' \
	./ "$STAGE/"

# The artifact must carry runtime files only. Copying the tree with an
# exclude list means the next stray file that appears here — a QA helper, a
# scratch script, a fixture corpus — ships to WordPress.org unnoticed, so the
# staged tree is checked against the allowlist instead.
unexpected="$(
	cd "$STAGE"
	find . -type f \
		! -path './assets/*' \
		! -path './src/*' \
		! -path './templates/*' \
		! -name 'relevant-forms.php' \
		! -name 'readme.txt' \
		-print
)"

if [ -n "$unexpected" ]; then
	echo "Unexpected files in the release artifact:" >&2
	echo "$unexpected" >&2
	echo "Runtime files are assets/, src/, templates/, relevant-forms.php and readme.txt." >&2
	exit 1
fi

(
	cd "$DIST"
	zip -rq "relevant-forms-$VERSION.zip" relevant-forms
)

echo "Built: $DIST/relevant-forms-$VERSION.zip"
unzip -l "$DIST/relevant-forms-$VERSION.zip" | tail -n 3
