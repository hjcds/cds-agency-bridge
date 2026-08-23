#!/usr/bin/env bash
# Build a release of CDS Agency Bridge and update the manifest.
#   ./release.sh 1.2.0 "<li>What changed</li>"
set -euo pipefail

VERSION="${1:?usage: ./release.sh <version> [changelog-html]}"
CHANGES="${2:-<li>Maintenance release</li>}"
BASE_URL="https://clouddigital.solutions/cds-bridge"
SRC="cds-agency-bridge/cds-agency-bridge.php"

# Bump version in the plugin header and the constant.
sed -i.bak -E "s/^( \* Version: +).*/\1${VERSION}/" "$SRC"
sed -i.bak -E "s/(define\( 'CDS_BRIDGE_VERSION', ')[^']+('\ \);)/\1${VERSION}\2/" "$SRC"
rm -f "${SRC}.bak"

# Zip with the folder name intact - WordPress installs to that folder name.
rm -f "cds-agency-bridge-${VERSION}.zip"
zip -rq "cds-agency-bridge-${VERSION}.zip" cds-agency-bridge -x '*.DS_Store'

# Rewrite the manifest.
python3 - "$VERSION" "$CHANGES" "$BASE_URL" <<'PY'
import json, sys
version, changes, base = sys.argv[1], sys.argv[2], sys.argv[3]
m = json.load(open('manifest.json'))
m['version'] = version
m['download_url'] = f"{base}/cds-agency-bridge-{version}.zip"
m['changelog'] = f"<h4>{version}</h4><ul>{changes}</ul>" + m.get('changelog','')
json.dump(m, open('manifest.json','w'), indent=2)
PY

echo "Built cds-agency-bridge-${VERSION}.zip and updated manifest.json"
echo "Now upload BOTH to ${BASE_URL}/ "
