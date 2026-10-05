#!/usr/bin/env bash
# ==============================================================================
# CorpQuiz Zero-Downtime Deployment Script
# ==============================================================================
set -euo pipefail

APP_DIR="/var/www/corpquiz"
cd "$APP_DIR"

echo ">>> [1/7] Pulling latest git changes..."
git pull origin master

echo ">>> [2/7] Installing production PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo ">>> [3/7] Compiling Employee SPA production bundle..."
if [ -f spa/package-lock.json ]; then
    npm --prefix spa ci
else
    npm --prefix spa install
fi
npm --prefix spa run build

echo ">>> [4/7] Caching configuration..."
php bin/console config:cache

echo ">>> [5/7] Executing database migrations..."
# Run any pending migrations
php bin/console migrations run

echo ">>> [6/7] Reloading PHP-FPM and restarting background workers..."
systemctl reload php8.2-fpm
systemctl restart quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs

echo ">>> [7/7] Smoke testing health check endpoint..."
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/healthz || echo "failed")

if [ "$HEALTH_STATUS" != "200" ]; then
    echo "ERROR: Health check returned $HEALTH_STATUS! Deployment verification failed."
    exit 1
fi

echo ">>> Deployment completed successfully! /healthz returned 200 OK."
