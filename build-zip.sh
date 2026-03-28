#!/usr/bin/env bash
# =============================================================================
# build-zip.sh — Build a production-ready WordPress plugin zip
#
# Usage:
#   ./build-zip.sh                 # uses version from plugin header
#   ./build-zip.sh 1.2.3           # override version
#   ./build-zip.sh --no-vendor     # skip composer install (use existing vendor/)
#   ./build-zip.sh --out /tmp/out  # put zip in a custom directory
#
# What it does:
#   1. Reads the version from the plugin PHP header (or uses the CLI override).
#   2. Installs production Composer dependencies (--no-dev, --optimize-autoloader).
#   3. Creates d5-design-system-helper-vX.Y.Z.zip containing ONLY production files:
#        d5-design-system-helper/
#          d5-design-system-helper.php
#          uninstall.php
#          README.md
#          CHANGELOG.md
#          LICENSE (if present)
#          assets/css/admin.css
#          assets/js/admin.js
#          includes/**
#          vendor/   (production only — no dev packages)
#   4. Restores development Composer dependencies.
#   5. Moves the zip to 'archived releases/' (or the --out directory).
#
# Requirements: bash, zip, php, composer (via composer.phar or system composer)
# =============================================================================

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────

PLUGIN_SLUG="d5-design-system-helper"
PLUGIN_FILE="${PLUGIN_SLUG}.php"
ARCHIVE_DIR="archived-releases"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# ── Parse arguments ────────────────────────────────────────────────────────────

OVERRIDE_VERSION=""
SKIP_VENDOR=false
OUT_DIR=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-vendor)   SKIP_VENDOR=true;  shift ;;
        --out)         OUT_DIR="$2";      shift 2 ;;
        --out=*)       OUT_DIR="${1#*=}"; shift ;;
        -*)            echo "Unknown flag: $1" >&2; exit 1 ;;
        *)             OVERRIDE_VERSION="$1"; shift ;;
    esac
done

# ── Move to project root ───────────────────────────────────────────────────────

cd "$SCRIPT_DIR"

# ── Read version from plugin header ───────────────────────────────────────────

if [[ -n "$OVERRIDE_VERSION" ]]; then
    VERSION="$OVERRIDE_VERSION"
else
    VERSION="$( grep -m1 '^ \* Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' )"
fi

if [[ -z "$VERSION" ]]; then
    echo "ERROR: Could not determine plugin version." >&2
    exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
OUT_DIR="${OUT_DIR:-$ARCHIVE_DIR}"

echo "=== Building ${ZIP_NAME} ==="
echo "  Version : ${VERSION}"
echo "  Output  : ${OUT_DIR}/${ZIP_NAME}"
echo ""

# ── Find Composer ──────────────────────────────────────────────────────────────

if command -v composer &>/dev/null; then
    COMPOSER="composer"
elif [[ -f "composer.phar" ]]; then
    COMPOSER="php composer.phar"
else
    echo "ERROR: Neither 'composer' nor 'composer.phar' found." >&2
    exit 1
fi

# ── Install production-only dependencies ──────────────────────────────────────

if [[ "$SKIP_VENDOR" == false ]]; then
    echo "--- Installing production dependencies (--no-dev) ---"
    $COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet
    echo "    Done."
fi

# ── JS syntax check ───────────────────────────────────────────────────────────

echo "--- Checking JS syntax ---"
if command -v node &>/dev/null; then
    JS_ERR="$( node --check "assets/js/admin.js" 2>&1 || true )"
    if [[ -n "$JS_ERR" ]]; then
        echo "ERROR: JS syntax error in assets/js/admin.js:" >&2
        echo "$JS_ERR" >&2
        exit 1
    fi
    echo "    JS syntax OK."
else
    echo "    (node not found — skipping JS syntax check)"
fi

# ── Create build directory ────────────────────────────────────────────────────

BUILD_DIR="$( mktemp -d )"
PLUGIN_BUILD_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
mkdir -p "$PLUGIN_BUILD_DIR"

trap 'rm -rf "$BUILD_DIR"' EXIT

# ── Copy production files ──────────────────────────────────────────────────────

echo "--- Copying production files ---"

# Root PHP files
cp "${PLUGIN_FILE}"   "$PLUGIN_BUILD_DIR/"
cp "uninstall.php"    "$PLUGIN_BUILD_DIR/"

# Documentation (only what belongs in a WP plugin)
[[ -f "README.md"            ]] && cp "README.md"            "$PLUGIN_BUILD_DIR/"
[[ -f "CHANGELOG.md"         ]] && cp "CHANGELOG.md"         "$PLUGIN_BUILD_DIR/"
[[ -f "PLUGIN_USER_GUIDE.md" ]] && cp "PLUGIN_USER_GUIDE.md" "$PLUGIN_BUILD_DIR/"
[[ -f "LICENSE"              ]] && cp "LICENSE"              "$PLUGIN_BUILD_DIR/"
[[ -f "LICENSE.txt"          ]] && cp "LICENSE.txt"          "$PLUGIN_BUILD_DIR/"

# Assets
mkdir -p "$PLUGIN_BUILD_DIR/assets/css"
mkdir -p "$PLUGIN_BUILD_DIR/assets/js"
cp "assets/css/admin.css"   "$PLUGIN_BUILD_DIR/assets/css/"
[[ -f "assets/css/tabulator.min.css" ]] && cp "assets/css/tabulator.min.css" "$PLUGIN_BUILD_DIR/assets/css/"
cp "assets/js/admin.js"    "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/fuse.min.js"         ]] && cp "assets/js/fuse.min.js"         "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/tabulator.min.js"    ]] && cp "assets/js/tabulator.min.js"    "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/manage-vars-table.js"          ]] && cp "assets/js/manage-vars-table.js"          "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/manage-presets-gp-table.js"    ]] && cp "assets/js/manage-presets-gp-table.js"    "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/manage-presets-ep-table.js"    ]] && cp "assets/js/manage-presets-ep-table.js"    "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/manage-presets-all-table.js"   ]] && cp "assets/js/manage-presets-all-table.js"   "$PLUGIN_BUILD_DIR/assets/js/"
[[ -f "assets/js/manage-everything-table.js"    ]] && cp "assets/js/manage-everything-table.js"    "$PLUGIN_BUILD_DIR/assets/js/"

# PHP source
cp -r "includes" "$PLUGIN_BUILD_DIR/"

# Vendor (production only — dev packages were excluded by --no-dev above)
cp -r "vendor" "$PLUGIN_BUILD_DIR/"

# ── Remove macOS metadata files ───────────────────────────────────────────────

find "$PLUGIN_BUILD_DIR" -name ".DS_Store" -delete
find "$PLUGIN_BUILD_DIR" -name "._*"       -delete

# ── Remove any stray dev/test files that may have crept into vendor ────────────

# PhpUnit and related test packages are dev-only, but double-check.
find "$PLUGIN_BUILD_DIR/vendor" \
    -type d \
    \( -name "phpunit" -o -name "phpstan" -o -name "psalm" \) \
    -exec rm -rf {} + 2>/dev/null || true

# ── Vendor sanity check ───────────────────────────────────────────────────────
# Abort if any dev-only package references survived into the autoload files.

echo "--- Verifying vendor is dev-free ---"
DEV_HITS="$( grep -rl "phpunit\|myclabs/deep-copy\|sebastian\|nikic/php-parser\|theseer" \
    "$PLUGIN_BUILD_DIR/vendor/composer/autoload_real.php" \
    "$PLUGIN_BUILD_DIR/vendor/composer/autoload_static.php" \
    "$PLUGIN_BUILD_DIR/vendor/composer/autoload_files.php" \
    2>/dev/null || true )"

if [[ -n "$DEV_HITS" ]]; then
    echo "ERROR: Dev packages found in vendor autoload files:" >&2
    echo "$DEV_HITS" >&2
    echo "Run: php composer.phar install --no-dev --optimize-autoloader" >&2
    exit 1
fi
echo "    Vendor is clean."

# ── Create zip ────────────────────────────────────────────────────────────────

mkdir -p "$OUT_DIR"

echo "--- Creating ${ZIP_NAME} ---"
( cd "$BUILD_DIR" && zip -r -q "${SCRIPT_DIR}/${OUT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}" \
    -x "*.DS_Store" -x "*/__MACOSX/*" -x "*/._*" )

ZIP_PATH="${SCRIPT_DIR}/${OUT_DIR}/${ZIP_NAME}"
ZIP_SIZE="$( du -sh "$ZIP_PATH" | cut -f1 )"

# ── Restore development dependencies ──────────────────────────────────────────

if [[ "$SKIP_VENDOR" == false ]]; then
    echo "--- Restoring dev dependencies ---"
    $COMPOSER install --optimize-autoloader --no-interaction --quiet
    echo "    Done."
fi

# ── Summary ───────────────────────────────────────────────────────────────────

echo ""
echo "=== Build complete ==="
echo "  File : ${ZIP_PATH}"
echo "  Size : ${ZIP_SIZE}"

# Show contents summary
echo ""
echo "  Contents:"
( cd "$BUILD_DIR" && find "${PLUGIN_SLUG}" -not -path "*/vendor/*" | sort | head -40 )
echo "  ... (vendor tree omitted)"
