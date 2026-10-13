#!/usr/bin/env bash
#
# Build the release artifact: runtime files only.
#
# The release zip contains no tests, dev tooling or vendored dependencies:
# the plugin autoloader falls back to a plain PSR-4 loader when vendor/ is
# absent (see relevant-forms.php).
#
# Two channels are cut from the same source:
#
#   production (default)  the WordPress.org artifact; talks to
#                         https://app.relevantforms.com
#   qa                    for testers; pinned to https://app.relevantforms.dev
#
# A channel is never inferred: the origin is written into the staged copy, and
# the build then asserts what it just produced. The QA origin must not appear in
# a production artifact, and a QA artifact must not be usable against
# production, because the difference between the two is which service a live
# site talks to.
#
# Usage:
#   composer build:release                     # production
#   bash bin/build-release.sh --channel=qa     # QA

set -euo pipefail

cd "$(dirname "$0")/.."

CHANNEL="production"
QA_ORIGIN="https://app.relevantforms.dev"
PRODUCTION_ORIGIN="https://app.relevantforms.com"

while [ $# -gt 0 ]; do
	case "$1" in
		--channel=*) CHANNEL="${1#--channel=}" ;;
		--channel)
			shift
			CHANNEL="${1:-}"
			;;
		*)
			echo "Unknown argument: $1" >&2
			exit 1
			;;
	esac
	shift
done

case "$CHANNEL" in
	production | qa) ;;
	*)
		echo "Unknown channel: $CHANNEL (expected 'production' or 'qa')" >&2
		exit 1
		;;
esac

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' relevant-forms.php | head -n 1)"

if [ -z "$VERSION" ]; then
	echo "Could not read the plugin version from relevant-forms.php" >&2
	exit 1
fi

DIST_BASE="dist"

if [ "$CHANNEL" = "qa" ]; then
	# A tester has to be able to tell which build they are running, and a
	# lower version than production means WordPress offers to move a site
	# back onto the released plugin.
	BUILD_VERSION="$VERSION-qa"
	DIST="$DIST_BASE/qa"
	ZIP_NAME="relevant-forms-$VERSION-qa.zip"
else
	BUILD_VERSION="$VERSION"
	DIST="$DIST_BASE"
	ZIP_NAME="relevant-forms-$VERSION.zip"
fi

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

# Stamp the channel into the staged copy only, never into the source tree.
if [ "$CHANNEL" = "qa" ]; then
	php "$(dirname "$0")/stamp-channel.php" \
		"$STAGE/relevant-forms.php" "$CHANNEL" "$QA_ORIGIN" "$BUILD_VERSION" "$VERSION"
fi

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
	zip -rq "$ZIP_NAME" relevant-forms
)

# What was just built has to be true of the artifact, not of the intent: the
# QA origin is written into QA builds only, so its presence in a production zip
# — or its absence from a QA one — is a hard failure.
if [ "$CHANNEL" = "qa" ]; then
	if ! grep -q "$QA_ORIGIN" "$STAGE/relevant-forms.php"; then
		echo "QA build does not pin $QA_ORIGIN" >&2
		exit 1
	fi

	if ! grep -q "RELEVANT_FORMS_CHANNEL', 'qa'" "$STAGE/relevant-forms.php"; then
		echo "QA build does not declare its channel" >&2
		exit 1
	fi
else
	if grep -rq "$QA_ORIGIN" "$STAGE" --exclude-dir='.'; then
		echo "Production build contains the QA origin ($QA_ORIGIN):" >&2
		grep -rl "$QA_ORIGIN" "$STAGE" >&2
		exit 1
	fi

	if grep -q "RELEVANT_FORMS_CHANNEL" "$STAGE/relevant-forms.php"; then
		echo "Production build declares a non-production channel" >&2
		exit 1
	fi

	if ! grep -q "$PRODUCTION_ORIGIN" "$STAGE/src/Cloud/CloudConfig.php"; then
		echo "Production build lost the production origin" >&2
		exit 1
	fi
fi

echo "Built: $DIST/$ZIP_NAME ($CHANNEL)"
unzip -l "$DIST/$ZIP_NAME" | tail -n 3
