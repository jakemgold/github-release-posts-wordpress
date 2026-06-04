#!/usr/bin/env bash
#
# svn-update-assets.sh — update ONLY the WordPress.org marketing assets
# (icon, banner, screenshots) in the SVN /assets directory.
#
# These live outside trunk/tags, so updating them does NOT require a version
# bump or a release — changes go live on the directory page within minutes.
# Source of truth is the .wordpress-org/ folder in this repo.
#
# Usage:
#   bin/svn-update-assets.sh https://plugins.svn.wordpress.org/auto-release-posts-for-github
#
# It checks out just the /assets subtree, syncs .wordpress-org/ into it, shows
# the pending changes, and PROMPTS before committing.

set -euo pipefail

SVN_URL="${1:-}"
if [[ -z "$SVN_URL" ]]; then
  echo "error: pass the SVN URL as the first argument." >&2
  echo "  bin/svn-update-assets.sh https://plugins.svn.wordpress.org/auto-release-posts-for-github" >&2
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

if ! compgen -G ".wordpress-org/*" > /dev/null; then
  echo "error: .wordpress-org/ has no files to upload." >&2
  exit 1
fi

# Check out only the /assets subtree (fast — avoids trunk/tags).
SVN_DIR="$(mktemp -d)"
trap 'rm -rf "${SVN_DIR:-}"' EXIT
echo "==> Checking out ${SVN_URL%/}/assets"
svn checkout "${SVN_URL%/}/assets" "$SVN_DIR"

# Sync marketing assets into the working copy (mirror: removes stale files too).
echo "==> Syncing .wordpress-org/ -> assets/"
rsync -a --delete --exclude='.DS_Store' --exclude='.svn' \
  .wordpress-org/ "$SVN_DIR/"

cd "$SVN_DIR"
svn add --force . > /dev/null
# Mark any files removed from .wordpress-org/ as SVN deletes.
svn status | awk '/^!/ {print $2}' | xargs -r svn rm --force > /dev/null || true

echo
echo "==> Pending asset changes:"
svn status
echo
read -r -p "Commit these assets to WordPress.org? [y/N] " reply
if [[ "$reply" == "y" || "$reply" == "Y" ]]; then
  svn commit -m "Update WordPress.org assets (icon/banner/screenshots)"
  echo "==> Done. The directory page refreshes within a few minutes."
else
  echo "==> Aborted before commit."
fi
