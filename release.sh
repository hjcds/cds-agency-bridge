#!/usr/bin/env bash
# Ship a new version of CDS Agency Bridge.
#   ./release.sh 1.2.0 "Added taxonomy SEO routes"
#
# Run from the repo root, where cds-agency-bridge.php lives. The script builds
# the correctly-structured zip itself, so the repo stays flat - no nested
# folder of the same name.
#
# Requires the gh CLI:  brew install gh && gh auth login
set -euo pipefail

VERSION="${1:?usage: ./release.sh <version> [release notes]}"
NOTES="${2:-Maintenance release}"
SRC="cds-agency-bridge.php"
SLUG="cds-agency-bridge"
ZIP="${SLUG}-${VERSION}.zip"

[ -f "$SRC" ] || { echo "Run this from the repo root (where $SRC lives)."; exit 1; }
command -v gh >/dev/null || { echo "gh CLI not found: brew install gh && gh auth login"; exit 1; }

# Bump the version in the plugin header and the constant.
sed -i.bak -E "s/^( \* Version: +).*/\1${VERSION}/" "$SRC"
sed -i.bak -E "s/(define\( 'CDS_BRIDGE_VERSION', ')[^']+(' \);)/\1${VERSION}\2/" "$SRC"
rm -f "${SRC}.bak"

grep -q "define( 'CDS_BRIDGE_VERSION', '${VERSION}' );" "$SRC" \
  || { echo "Version bump failed - check $SRC"; exit 1; }

# Build the zip with the plugin folder inside, which is what WordPress expects.
rm -rf ".build" "$ZIP"
mkdir -p ".build/${SLUG}"
cp "$SRC" ".build/${SLUG}/"
( cd .build && zip -rq "../${ZIP}" "$SLUG" -x '*.DS_Store' )
rm -rf .build

git add -A
git commit -m "Release ${VERSION}: ${NOTES}"
git push

gh release create "v${VERSION}" "$ZIP" --title "v${VERSION}" --notes "${NOTES}"

rm -f "$ZIP"   # the release holds it now; no build artefacts in the repo

echo "Released v${VERSION}. Sites pick it up within 6 hours."
