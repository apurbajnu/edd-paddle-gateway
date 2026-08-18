#!/usr/bin/env bash
# Build a clean distributable zip of the EDD Paddle Gateway plugin.
#
# Excludes dev-only artifacts (.git, tests, vendor, IDE configs, *.log) so
# buyers get a lean plugin package (~tens of KB instead of multi-MB).
#
# Output: ../edd-paddle-gateway.zip (next to the plugin folder)
# When the user renames this folder to edd-paddle-gateway/ for wp.org
# submission, the slug + zip name follow automatically.
set -euo pipefail

PLUGIN_SLUG="$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)")"
# Main plugin file basename — must match the wp.org slug (edd-paddle-gateway.php),
# not the dev folder name (which may have a -free suffix during development).
PLUGIN_FILE="edd-paddle-gateway.php"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_PARENT="$(dirname "${PLUGIN_DIR}")"                            # .../wp-content/plugins  (zip runs here)
SITE_ROOT="$(dirname "$(dirname "${PLUGINS_PARENT}")")"                # .../bestdecoders        (zip lands here)
DIST_ZIP="${SITE_ROOT}/${PLUGIN_SLUG}.zip"

# Sanity: plugin file present?
if [ ! -f "${PLUGIN_DIR}/${PLUGIN_FILE}" ]; then
    echo "Error: ${PLUGIN_FILE} not found in ${PLUGIN_DIR}" >&2
    exit 1
fi

# Optional: warn on uncommitted changes (don't fail — operator may have built deliberately).
if [ -d "${PLUGIN_DIR}/.git" ] && command -v git >/dev/null 2>&1; then
    if [ -n "$(git -C "${PLUGIN_DIR}" status --porcelain 2>/dev/null)" ]; then
        echo "Warning: git working tree has uncommitted changes." >&2
    fi
fi

# Drop the previous artifact (if any).
rm -f "${DIST_ZIP}"

# Zip from PLUGINS_PARENT so the archive root contains the plugin folder by name.
cd "${PLUGINS_PARENT}"

zip -rq "${DIST_ZIP}" "${PLUGIN_SLUG}" \
    -x "${PLUGIN_SLUG}/.git/*" \
       "${PLUGIN_SLUG}/.gitignore" \
       "${PLUGIN_SLUG}/.distignore" \
       "${PLUGIN_SLUG}/.github/*" \
       "${PLUGIN_SLUG}/.vscode/*" \
       "${PLUGIN_SLUG}/.idea/*" \
       "${PLUGIN_SLUG}/.DS_Store" \
       "${PLUGIN_SLUG}/composer.json" \
       "${PLUGIN_SLUG}/composer.lock" \
       "${PLUGIN_SLUG}/phpunit.xml.dist" \
       "${PLUGIN_SLUG}/.phpunit.result.cache" \
       "${PLUGIN_SLUG}/build.sh" \
       "${PLUGIN_SLUG}/tests/*" \
       "${PLUGIN_SLUG}/vendor/*" \
       "${PLUGIN_SLUG}/*.log" \
       "${PLUGIN_SLUG}/*.tar.gz"
# NOTE: globs in -x patterns stay literal — bash word-splitting doesn't expand
# them because they're inside double-quoted strings, and zip itself does the
# path matching against archive entries.

# Report
BUILT_SIZE="$(du -h "${DIST_ZIP}" | cut -f1)"
FILE_COUNT="$(unzip -l "${DIST_ZIP}" | tail -1 | awk '{print $2}')"
echo "Built: ${DIST_ZIP}"
echo "Size:  ${BUILT_SIZE} (${FILE_COUNT} files)"
