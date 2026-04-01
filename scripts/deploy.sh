#!/usr/bin/env bash
# CORRAD — production deployment (run on server from project root).
# Prerequisites: PHP 8.2+, Composer, Node 20+, .env configured, APP_KEY set.
set -euo pipefail

# Avoid Composer killing long installs (default 300s).
export COMPOSER_PROCESS_TIMEOUT="${COMPOSER_PROCESS_TIMEOUT:-0}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> composer install (no dev)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
php artisan migrate --force --no-interaction

echo "==> optimize (config, route, view, event caches)"
php artisan optimize

echo "==> Vue admin SPA → public/spa (VITE_BUILD_FOR_LARAVEL)"
npm --prefix client ci
npm --prefix client run build:laravel

echo "==> queue workers pick up new code"
php artisan queue:restart 2>/dev/null || true

echo "==> done"
