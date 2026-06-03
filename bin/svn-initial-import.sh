#!/usr/bin/env bash
#
# svn-initial-import.sh — ONE-TIME manual import of the current version into the
# WordPress.org SVN repository.
#
# Use this for the very first WordPress.org release only. Every release after
# this is handled automatically by .github/workflows/deploy.yml (publish a
# GitHub Release). Run this from the repo root once your SVN URL has arrived in
# the WordPress.org "approved" email.
#
# Usage:
#   bin/svn-initial-import.sh https://plugins.svn.wordpress.org/auto-release-posts-for-github [VERSION]
#
# VERSION defaults to the Stable tag in readme.txt (currently 1.0.2).
#
# It will: build the plugin, stage the exact files that ship (the allowlist from
# CLAUDE.md), copy them into SVN trunk/ and tags/<VERSION>/, move .wordpress-org
# marketing assets into SVN assets/, then PROMPT before the final `svn commit`.

set -euo pipefail

SVN_URL="${1:-}"
if [[ -z "$SVN_URL" ]]; then
  echo "error: pass the SVN URL as the first argument." >&2
  echo "  bin/svn-initial-import.sh https://plugins.svn.wordpress.org/auto-release-posts-for-github" >&2
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

VERSION="${2:-$(grep -i '^Stable tag:' readme.txt | sed -E 's/.*:[[:space:]]*//' | tr -d '[:space:]')}"
if [[ -z "$VERSION" ]]; then
  echo "error: could not determine VERSION from readme.txt; pass it as the 2nd argument." >&2
  exit 1
fi
echo "==> Importing version: $VERSION"

# 1. Build exactly what ships.
echo "==> Building assets and production autoloader"
npm ci
npm run build
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Stage the shipping files (allowlist mirrors CLAUDE.md's Local-site rsync).
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE" "$SVN_DIR"' EXIT
echo "==> Staging plugin files in $STAGE"
rsync -a \
  --exclude='**/.DS_Store' --exclude='**/.gitkeep' \
  --include='github-release-posts.php' --include='uninstall.php' --include='readme.txt' \
  --include='composer.json' --include='composer.lock' \
  --include='includes/***' --include='dist/***' --include='assets/css/***' \
  --include='assets/js/***' --include='assets/' --include='languages/***' \
  --include='vendor/***' --exclude='*' \
  ./ "$STAGE/"

# 3. Check out the (empty) SVN repo.
SVN_DIR="$(mktemp -d)"
echo "==> Checking out SVN repo into $SVN_DIR"
svn checkout "$SVN_URL" "$SVN_DIR"

mkdir -p "$SVN_DIR/trunk" "$SVN_DIR/assets" "$SVN_DIR/tags/$VERSION"

# 4. Populate trunk and the version tag with identical built files.
echo "==> Copying build into trunk/ and tags/$VERSION/"
rsync -a --delete "$STAGE/" "$SVN_DIR/trunk/"
rsync -a --delete "$STAGE/" "$SVN_DIR/tags/$VERSION/"

# 5. Marketing assets (screenshots/banners/icons) live in SVN /assets, not trunk.
if compgen -G ".wordpress-org/*" > /dev/null; then
  echo "==> Copying .wordpress-org/* into assets/"
  rsync -a --exclude='.DS_Store' .wordpress-org/ "$SVN_DIR/assets/"
fi

# 6. Stage adds/removes for SVN.
cd "$SVN_DIR"
svn add --force . > /dev/null
# Mark any files missing on disk as deleted in SVN (none expected on a fresh import).
svn status | awk '/^!/ {print $2}' | xargs -r svn rm --force > /dev/null || true

echo
echo "==> Review the pending SVN changes:"
svn status
echo
read -r -p "Commit this import to WordPress.org? [y/N] " reply
if [[ "$reply" == "y" || "$reply" == "Y" ]]; then
  svn commit -m "Initial WordPress.org release $VERSION"
  echo "==> Done. https://wordpress.org/plugins/auto-release-posts-for-github/ will update shortly."
else
  echo "==> Aborted before commit. SVN working copy left at: $SVN_DIR"
  trap - EXIT
  rm -rf "$STAGE"
fi
