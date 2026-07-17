#!/usr/bin/env bash
# =============================================================================
# restart.sh — ChatApp backend restart script
# Usage:  bash restart.sh
#
# Env overrides (defaults shown):
#   APP_DIR        — /home/khelboo_admin/webnaire/test2
#   OWNER_USER     — khelboo_admin
#   OWNER_GROUP    — www-data
# =============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/home/khelboo_admin/webnaire/test2}"
OWNER_USER="${OWNER_USER:-khelboo_admin}"
OWNER_GROUP="${OWNER_GROUP:-www-data}"

if [[ ! -d "$APP_DIR" ]]; then
    echo "❌ APP_DIR not found: $APP_DIR"
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "❌ php binary not found in PATH"
    exit 1
fi

cd "$APP_DIR"

set_runtime_permissions() {
    echo "==> Ensuring Laravel writable directories exist..."

    mkdir -p \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        bootstrap/cache

    echo "==> Applying ownership/permissions: $OWNER_USER:$OWNER_GROUP"
    sudo chown -R "$OWNER_USER":"$OWNER_GROUP" storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
    sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
}

echo "==> Clearing Laravel caches..."
php artisan optimize:clear

echo "==> Caching config, routes, views and events..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Running artisan optimize..."
php artisan optimize

echo

echo "==> Re-applying runtime permissions..."
set_runtime_permissions

echo

echo
echo "✅  Restart complete."