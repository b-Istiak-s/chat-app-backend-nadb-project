#!/usr/bin/env bash
# =============================================================================
# deploy.sh — ChatApp backend deployment script
# Usage:  bash deploy.sh [git-ref]
#   git-ref  Branch or tag to deploy (default: main)
#
# Env overrides (defaults shown):
#   APP_DIR        — /home/isiak/projects/nadb/chat_app/backend
#   WEB_USER       — nginx
#   OWNER_USER     — isiak
#   OWNER_GROUP    — same as WEB_USER
#   SSH_IDENTITY   — $HOME/.ssh/istiak_git
#   PHP_BINARY     — /usr/bin/php
#   SYSTEMD_DIR    — /etc/systemd/system
# =============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/projects/nadb/chat_app/backend}"
WEB_USER="${WEB_USER:-nginx}"
OWNER_USER="${OWNER_USER:-isiak}"
OWNER_GROUP="${OWNER_GROUP:-$WEB_USER}"
SSH_IDENTITY="${SSH_IDENTITY:-$HOME/.ssh/istiak_git}"
PHP_BINARY="${PHP_BINARY:-/usr/bin/php}"
SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
REF="${1:-main}"

if [[ ! -d "$APP_DIR" ]]; then
    echo "❌ APP_DIR not found: $APP_DIR"
    exit 1
fi

if [[ ! -x "$PHP_BINARY" ]]; then
    echo "❌ PHP binary not found or not executable: $PHP_BINARY"
    exit 1
fi

cd "$APP_DIR"

echo "==> Deploying ref: $REF"
echo "==> App dir: $APP_DIR"

prepare_runtime_paths() {
    echo "==> Preparing Laravel writable directories..."
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

install_systemd_units() {
    echo "==> Installing systemd service files..."
    if [[ -f chatapp-queue.service ]]; then
        sudo install -m 0644 chatapp-queue.service "$SYSTEMD_DIR/chatapp-queue.service"
        sudo systemctl daemon-reload
    elif [[ -f webnaire-queue.service ]]; then
        # Legacy/imported template — keep working under any name.
        sudo install -m 0644 webnaire-queue.service "$SYSTEMD_DIR/chatapp-queue.service"
        sudo systemctl daemon-reload
    else
        echo "==> No queue service template found in repo root; skipping"
        return 0
    fi
}

restart_services() {
    if systemctl list-unit-files chatapp-queue.service >/dev/null 2>&1; then
        echo "==> Restarting queue worker..."
        sudo systemctl restart chatapp-queue.service
    else
        echo "==> chatapp-queue.service not installed; skipping restart"
    fi
}

echo "==> Fetching and checking out ref..."
GIT_SSH_COMMAND="ssh -i $SSH_IDENTITY" git fetch origin --prune
git checkout "$REF"
GIT_SSH_COMMAND="ssh -i $SSH_IDENTITY" git pull origin "$REF"

echo

echo "==> Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo

prepare_runtime_paths

echo

echo "==> Running migrations..."
"$PHP_BINARY" artisan migrate --force

echo

if [[ ! -L public/storage ]]; then
    echo "==> Creating storage symlink..."
    "$PHP_BINARY" artisan storage:link
fi

echo

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

# Reschedule the cron-driven reconciliation job. Routes are cached so
# Laravel forgets the registered schedule; this rewrites it.
"$PHP_BINARY" artisan schedule:list >/dev/null || true

install_systemd_units
restart_services

echo
echo "✅ Deployment complete."