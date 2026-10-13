#!/usr/bin/env bash
#
# Guard against publishing anything that is not the plugin.
#
# The public mirror exists so the plugin can be developed in the open without
# exposing the private monorepo it is authored in. A path allowlist does most of
# that work at rewrite time, but an allowlist only protects the rewrite that was
# configured — this checks the result, and it runs both when publishing and, as a
# CI job, on every push to the mirror, so the promise keeps holding.
#
# Usage: guard.sh [repo]      (default: the repository this script lives in)
#
# Exits non-zero, listing every offence, when the repository carries backend
# code, monorepo-only paths, internal references or credentials.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="${1:-$(cd "$script_dir/.." && pwd)}"

if ! git -C "$repo" rev-parse --git-dir >/dev/null 2>&1; then
	echo "Not a git repository: $repo" >&2
	exit 1
fi

failures=0

fail() {
	printf '  x %s\n' "$1" >&2
	failures=$((failures + 1))
}

# ---------------------------------------------------------------------------
# 1. Paths. Every path in every commit has to be plugin content. This catches a
#    rewrite that kept too much, including paths deleted later: the history is
#    what gets published, not only the current tree.
# ---------------------------------------------------------------------------
while IFS= read -r path; do
	[ -n "$path" ] || continue

	case "$path" in
		backend/* | deploy/* | docs/* | .vscode/*)
			fail "monorepo path in history: $path"
			;;
		.gitlab-ci.yml)
			fail "monorepo file in history: $path"
			;;
		sentinel-forms/*)
			# The plugin carried this name before the rebrand, and those
			# commits are published as they were. Only the current era is
			# flattened, so the directory must not survive into HEAD.
			if git -C "$repo" cat-file -e "HEAD:$path" 2>/dev/null; then
				fail "legacy directory still present at HEAD: $path"
			fi
			;;
		wp-plugin/* | relevant-forms/*)
			# wp-plugin/ is not published at all (it named the private
			# monorepo), and relevant-forms/ should have been flattened.
			fail "unexpected source path in history: $path"
			;;
	esac
done < <(git -C "$repo" log --all --format= --name-only | sort -u)

# ---------------------------------------------------------------------------
# 2. Content of every blob ever committed. Reads the history rather than HEAD,
#    because a string committed once stays reachable in the published objects
#    even after it is deleted.
# ---------------------------------------------------------------------------
if ! python3 "$script_dir/guard-content.py" "$repo"; then
	failures=$((failures + 1))
fi

# ---------------------------------------------------------------------------
# 3. Commit messages, which the blob scan cannot see.
# ---------------------------------------------------------------------------
for reference in 'gitlab.deep9.se' 'wp-sentinel-form' 'sentinel-form/' '172.20.0.13'; do
	if git -C "$repo" log --all --format=%B | grep -qF "$reference"; then
		fail "internal reference in commit messages: $reference"
	fi
done

if [ "$failures" -gt 0 ]; then
	printf '\n%d problem(s): refusing to publish.\n' "$failures" >&2
	exit 1
fi

printf 'Guard passed: %s commit(s), plugin content only.\n' "$(git -C "$repo" rev-list --count HEAD)"
