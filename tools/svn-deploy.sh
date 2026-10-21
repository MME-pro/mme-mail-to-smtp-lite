#!/usr/bin/env bash
# Publish the committed plugin to the wordpress.org SVN checkout: mirror it into
# trunk/, copy trunk/ to tags/<version>/, and commit both in one revision.
#
#   tools/svn-deploy.sh 1.0.2            # sync, tag, commit
#   tools/svn-deploy.sh 1.0.2 --dry-run  # sync and tag on disk, show svn status, no commit
#
# What ships is what git tracks, minus the development-only folders. Untracked
# or ignored files (node_modules/, a stray .pem) never reach the directory, so
# commit to git first: an uncommitted edit is not shipped.

set -euo pipefail

VERSION="${1:-}"
DRY_RUN="${2:-}"
SVN_DIR="${SVN_DIR:-/d/Talha/MME Mail to SMTP}"
EXCLUDE='^(tests|tools|docs|\.claude)/|^\.git'

die() { echo "deploy: $*" >&2; exit 1; }

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "usage: tools/svn-deploy.sh <x.y.z> [--dry-run]"

cd "$(dirname "$0")/.."

SVN="$(command -v svn || true)"
for c in "/c/Program Files/TortoiseSVN/bin/svn.exe" "/c/Program Files/SlikSvn/bin/svn.exe"; do
	[[ -z "$SVN" && -x "$c" ]] && SVN="$c"
done
[[ -n "$SVN" || "$DRY_RUN" == "--dry-run" ]] || die "no svn command-line client found (install TortoiseSVN's command line client tools)"

[[ -d "$SVN_DIR/.svn" ]] || die "$SVN_DIR is not an SVN checkout"
[[ -e "$SVN_DIR/tags/$VERSION" ]] && die "tags/$VERSION already exists - a version number is never reused"

# The four version lines must agree, or sites are offered the wrong update.
grep -q "^ \* Version: *$VERSION\$" mme-mail-to-smtp.php || die "plugin header Version is not $VERSION"
grep -q "^const VERSION *= '$VERSION';" mme-mail-to-smtp.php || die "const VERSION is not $VERSION"
grep -q "^Stable tag: $VERSION\$" readme.txt || die "readme.txt Stable tag is not $VERSION"
grep -q "\"version\": \"$VERSION\"" package.json || die "package.json version is not $VERSION"
grep -q "^= $VERSION =" readme.txt || die "readme.txt has no changelog entry for $VERSION"

[[ -z "$(git status --porcelain)" ]] || die "git working tree is not clean - commit first, only committed files ship"

[[ -n "$SVN" ]] && "$SVN" update "$SVN_DIR"

# Mirror: empty trunk/, then copy the shipped files back in. trunk/ holds no
# .svn of its own - the working copy keeps its metadata at the root.
find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
git ls-files -z | grep -zvE "$EXCLUDE" | while IFS= read -r -d '' f; do
	mkdir -p "$SVN_DIR/trunk/$(dirname "$f")"
	cp "$f" "$SVN_DIR/trunk/$f"
done

cp -r "$SVN_DIR/trunk" "$SVN_DIR/tags/$VERSION"

if [[ -z "$SVN" ]]; then
	echo "deploy: dry run - trunk/ and tags/$VERSION/ written, no svn client to show status"
	exit 0
fi

cd "$SVN_DIR"
"$SVN" add --force --quiet trunk "tags/$VERSION"
# Files removed from the plugin show up as missing; tell svn they are deleted.
"$SVN" status trunk | sed -n 's/^! *//p' | while IFS= read -r f; do "$SVN" rm --quiet "$f"; done

"$SVN" status

if [[ "$DRY_RUN" == "--dry-run" ]]; then
	echo "deploy: dry run - nothing committed"
	exit 0
fi

"$SVN" commit -m "Release $VERSION"
echo "deploy: $VERSION is in trunk and tags/$VERSION"
