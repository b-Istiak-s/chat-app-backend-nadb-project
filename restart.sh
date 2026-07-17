#!/usr/bin/env bash
# =============================================================================
# restart.sh — ChatApp backend restart script
# Usage:  bash restart.sh
#
# Env overrides (defaults shown):
#   APP_DIR        — /home/isiak/projects/nadb/chat_app/backend
#   WEB_USER       — nginx
#   OWNER_USER     — isiak
#   OWNER_GROUP    — same as WEB_USER
#   PHP_BINARY     — /usr/bin/php
# =============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/projects/nadb/chat_app/backend}"
WEB_USER="${WEB_USER:-nginx}"
OWNER_USER="${OWNER_USER:-isiak}"
OWNER_GROUP="${OWNER_GROUP:-$WEB_USER}"
PHP_BINARY="${PHP_BINARY:-/usr/bin/php}"

if [[ ! -d "$APP_DIR" ]]; then
    echo "❌ APP_DIR not found: $APP_DIR"
    exit 1
fi

if [[ ! -x "$PHP_BINARY" ]]; then
    echo "❌ PHP binary not found or not executable: $PHP_BINARY"
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
"$PHP_BINARY" artisan optimize:clear

echo "==> Caching config, routes, views and events..."
"$PHP_BINARY" artisan config:cache
"$PHP_BINARY" artisan route:cache
"$PHP_BINARY" artisan view:cache
"$PHP_BINARY" artisan event:cache

echo "==> Running artisan optimize..."
"$PHP_BINARY" artisan optimize

echo

echo "==> Re-applying runtime permissions..."
set_runtime_permissions

echo

if systemctl list-unit-files chatapp-queue.service >/dev/null 2>&1; then
    echo "==> Restarting queue worker..."
    sudo systemctl restart chatapp-queue.service
else
    echo "==> chatapp-queue.service not installed; skipping restart"
fi

echo
echo "✅  Restart complete."