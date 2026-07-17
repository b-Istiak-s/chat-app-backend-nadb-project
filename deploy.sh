#!/usr/bin/env bash
# =============================================================================
# deploy.sh — ChatApp backend deployment script
# Usage:  bash deploy.sh [git-ref]
#   git-ref  Branch or tag to deploy (default: main)
#
# Env overrides (defaults shown):
#   APP_DIR        — /home/khelboo_admin/webnaire/test2
#   OWNER_USER     — khelboo_admin
#   OWNER_GROUP    — www-data
#   SSH_IDENTITY   — $HOME/.ssh/istiak_git
# =============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/home/khelboo_admin/webnaire/test2}"
OWNER_USER="${OWNER_USER:-khelboo_admin}"
OWNER_GROUP="${OWNER_GROUP:-www-data}"
SSH_IDENTITY="${SSH_IDENTITY:-$HOME/.ssh/istiak_git}"
REF="${1:-main}"

if [[ ! -d "$APP_DIR" ]]; then
    echo "❌ APP_DIR not found: $APP_DIR"
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "❌ php binary not found in PATH"
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
php artisan migrate --force

echo

if [[ ! -L public/storage ]]; then
    echo "==> Creating storage symlink..."
    php artisan storage:link
fi

echo

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

# Reschedule the cron-driven reconciliation job. Routes are cached so
# Laravel forgets the registered schedule; this rewrites it.
php artisan schedule:list >/dev/null || true

echo
echo "✅ Deployment complete."