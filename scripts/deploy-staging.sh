#!/usr/bin/env bash
set -euo pipefail

# Deploys this checkout of the FREE plugin to the staging WordPress behind
# https://staging.wpwebhooks.org/
#
# Runs on the self-hosted GitHub Actions runner, which lives on the same VPS as
# the staging stack — so this is a local rsync. No SSH, no secrets, no build:
# vendor/ and admin/dist/ are both tracked in this repo.
#
#   ./scripts/deploy-staging.sh

PLUGIN_SLUG="flowsystems-webhook-actions"
STAGING_ROOT="${STAGING_ROOT:-/srv/docker/staging.wpwebhooks/wordpress}"
CONTAINER="${STAGING_CONTAINER:-caloriehub_wordpress}"

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${STAGING_ROOT}/wp-content/plugins/${PLUGIN_SLUG}"

# Guards. rsync --delete is unforgiving, so refuse to run unless both ends are
# demonstrably what we think they are.
[ -f "${STAGING_ROOT}/wp-settings.php" ] \
  || { echo "✗ ${STAGING_ROOT} is not a WordPress root"; exit 1; }
[ -f "${SOURCE_DIR}/${PLUGIN_SLUG}.php" ] \
  || { echo "✗ ${SOURCE_DIR} is not the ${PLUGIN_SLUG} plugin"; exit 1; }

VERSION="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-]+' "${SOURCE_DIR}/${PLUGIN_SLUG}.php")"
echo "==> ${PLUGIN_SLUG} ${VERSION} → ${TARGET_DIR}"

mkdir -p "${TARGET_DIR}"

# Mirrors deploy.sh's WP.org exclude list, plus the repo-only files.
rsync -a --delete --info=stats1 \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='scripts' \
  --exclude='CLAUDE.md' \
  --exclude='node_modules' \
  --exclude='admin/src' \
  --exclude='admin/package.json' \
  --exclude='admin/package-lock.json' \
  --exclude='admin/vite.config.js' \
  --exclude='admin/tailwind.config.js' \
  --exclude='admin/.nvmrc' \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

echo "==> Activating and flushing"
docker exec "${CONTAINER}" wp plugin activate "${PLUGIN_SLUG}" --allow-root
docker exec "${CONTAINER}" wp cache flush --allow-root

echo "==> Deployed version on staging:"
docker exec "${CONTAINER}" wp plugin get "${PLUGIN_SLUG}" --field=version --allow-root
