#!/usr/bin/env bash
# Ship a new version of CDS Agency Bridge.
#   ./release.sh 1.2.0 "Added taxonomy SEO routes"
#
# Bumps the version, builds the zip, tags, and publishes a GitHub release
# with the zip attached. Requires the gh CLI (brew install gh; gh auth login).
set -euo pipefail

VERSION="${1:?usage: ./release.sh <version> [release notes]}"
NOTES="${2:-Maintenance release}"
SRC="cds-agency-bridge/cds-agency-bridge.php"
ZIP="cds-agency-bridge-${VERSION}.zip"

[ -f "$SRC" ] || { echo "Run this from the folder containing cds-agency-bridge/"; exit 1; }

# Bump version in the plugin header and the constant.
sed -i.bak -E "s/^( \* Version: +).*/\1${VERSION}/" "$SRC"
sed -i.bak -E "s/(define\( 'CDS_BRIDGE_VERSION', ')[^']+(' \);)/\1${VERSION}\2/" "$SRC"
rm -f "${SRC}.bak"

grep -q "'${VERSION}'" "$SRC" || { echo "Version bump failed - check $SRC"; exit 1; }

# Zip with the folder intact; WordPress installs to that folder name.
rm -f "$ZIP"
zip -rq "$ZIP" cds-agency-bridge -x '*.DS_Store'

git add -A
git commit -m "Release ${VERSION}: ${NOTES}"
git push

gh release create "v${VERSION}" "$ZIP" --title "v${VERSION}" --notes "${NOTES}"

echo "Released v${VERSION}. Sites will pick it up within 6 hours."
echo "To force a site now: delete its cds_bridge_manifest site transient, or hit /cds/v1/update-status."
