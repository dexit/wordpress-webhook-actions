#!/usr/bin/env bash
set -euo pipefail

# Ensures the WordPress MCP Adapter is installed and active on staging.
#
# The adapter is NOT on WordPress.org — its readme.txt advertises
# `wp plugin install mcp-adapter`, but that slug does not exist. It has to be
# built from source, and it needs Composer dependencies (jetpack-autoloader,
# php-mcp-schema). There is no composer on the VPS, so we run the official
# composer image; nothing is installed on the host.
#
# Idempotent: re-installs only when the pinned version is missing or differs.
#
#   ./scripts/staging-ensure-mcp-adapter.sh

ADAPTER_VERSION="${ADAPTER_VERSION:-0.6.1}"
ADAPTER_REPO="https://github.com/WordPress/mcp-adapter.git"
STAGING_ROOT="${STAGING_ROOT:-/srv/docker/staging.wpwebhooks/wordpress}"
CONTAINER="${STAGING_CONTAINER:-caloriehub_wordpress}"

TARGET_DIR="${STAGING_ROOT}/wp-content/plugins/mcp-adapter"

[ -f "${STAGING_ROOT}/wp-settings.php" ] \
  || { echo "✗ ${STAGING_ROOT} is not a WordPress root"; exit 1; }

installed=""
if [ -f "${TARGET_DIR}/mcp-adapter.php" ]; then
  installed="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-]+' "${TARGET_DIR}/mcp-adapter.php" || true)"
fi

if [ "${installed}" = "${ADAPTER_VERSION}" ]; then
  echo "==> MCP Adapter ${ADAPTER_VERSION} already installed"
else
  echo "==> Installing MCP Adapter ${ADAPTER_VERSION} (found: ${installed:-none})"
  BUILD_DIR="$(mktemp -d)"
  trap 'rm -rf "${BUILD_DIR}"' EXIT

  git clone --quiet --depth 1 --branch "${ADAPTER_VERSION}" "${ADAPTER_REPO}" "${BUILD_DIR}/mcp-adapter" 2>/dev/null \
    || git clone --quiet --depth 1 "${ADAPTER_REPO}" "${BUILD_DIR}/mcp-adapter"

  docker run --rm \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "${BUILD_DIR}/mcp-adapter:/app" \
    composer:2 install --no-dev --optimize-autoloader --no-interaction --quiet

  mkdir -p "${TARGET_DIR}"
  rsync -a --delete \
    --exclude='.git' --exclude='tests' --exclude='node_modules' \
    "${BUILD_DIR}/mcp-adapter/" "${TARGET_DIR}/"
fi

docker exec "${CONTAINER}" wp plugin activate mcp-adapter --allow-root

echo "==> MCP server route:"
docker exec "${CONTAINER}" wp eval '
$routes = array_keys(rest_get_server()->get_routes());
foreach ($routes as $r) { if (strpos($r, "/mcp") === 0) echo "  $r\n"; }
' --allow-root
