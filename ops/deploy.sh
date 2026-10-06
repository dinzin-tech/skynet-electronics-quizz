#!/usr/bin/env bash
# ==============================================================================
# CorpQuiz Zero-Downtime Production Deployment Script
# Target: Ubuntu 22.04 / 24.04 LTS (Hostinger KVM VPS)
# ==============================================================================
set -euo pipefail

# Export environment paths to ensure composer, node, and php are in PATH
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

APP_DIR="${APP_DIR:-/var/www/corpquiz}"
TARGET_BRANCH="${1:-master}"

echo "========================================================================"
echo ">>> Starting CorpQuiz Deployment at $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo ">>> Target Directory : $APP_DIR"
echo ">>> Target Branch    : $TARGET_BRANCH"
echo ">>> Running User     : $(whoami) (UID: $(id -u))"
echo "========================================================================"

if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: Target directory $APP_DIR does not exist!"
    exit 1
fi

cd "$APP_DIR"

# Sudo wrapper for non-root deployment users
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    SUDO="sudo"
fi

echo ">>> [1/7] Fetching and updating git branch: $TARGET_BRANCH..."
git fetch origin "$TARGET_BRANCH"
git checkout "$TARGET_BRANCH"
git pull origin "$TARGET_BRANCH"

echo ">>> [2/7] Installing production PHP dependencies via Composer..."
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo ">>> [3/7] Compiling Employee React SPA production bundle..."
if [ -f spa/package-lock.json ]; then
    npm --prefix spa ci --silent
else
    npm --prefix spa install --silent
fi
npm --prefix spa run build

echo ">>> [4/7] Caching configuration and routes..."
php bin/console config:cache

echo ">>> [5/7] Executing pending database migrations..."
php bin/console migrations run

echo ">>> [6/7] Reloading PHP-FPM, Nginx, and restarting background daemons..."
# Detect active PHP-FPM service (defaults to php8.2-fpm, falls back to php8.3-fpm)
PHP_FPM="php8.2-fpm"
if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
    PHP_FPM="php8.3-fpm"
fi

echo ">>> Reloading $PHP_FPM..."
$SUDO systemctl reload "$PHP_FPM"

# Sync & validate Nginx configuration changes if modified
if [ -f "$APP_DIR/ops/nginx/corpquiz.conf" ] && [ -d /etc/nginx/sites-available ]; then
    if ! cmp -s "$APP_DIR/ops/nginx/corpquiz.conf" /etc/nginx/sites-available/corpquiz.conf 2>/dev/null; then
        echo ">>> Nginx config change detected, syncing and reloading..."
        $SUDO cp "$APP_DIR/ops/nginx/corpquiz.conf" /etc/nginx/sites-available/corpquiz.conf
        if $SUDO nginx -t >/dev/null 2>&1; then
            $SUDO systemctl reload nginx
            echo ">>> Nginx reloaded successfully."
        else
            echo "WARNING: Nginx configuration test failed, skipping Nginx reload."
        fi
    fi
fi

# Sync Prometheus alert rules if Prometheus is running locally
if [ -d /etc/prometheus ] && systemctl is-active --quiet prometheus 2>/dev/null; then
    if [ -f "$APP_DIR/ops/observability/prometheus/alert.rules.yml" ]; then
        $SUDO cp "$APP_DIR/ops/observability/prometheus/alert.rules.yml" /etc/prometheus/alert.rules.yml
        $SUDO systemctl reload prometheus 2>/dev/null || true
    fi
fi

echo ">>> Restarting background workers..."
$SUDO systemctl restart quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs

# Ensure proper permissions for web server and storage
echo ">>> Updating file permissions for storage & public assets..."
$SUDO chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/public" 2>/dev/null || true
$SUDO chmod -R 775 "$APP_DIR/storage" 2>/dev/null || true

echo ">>> [7/7] Verifying application health endpoint..."
# Try health check with Host header first, then fallback to direct IP
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: quiz.corp.local" http://127.0.0.1/healthz || echo "failed")
if [ "$HEALTH_STATUS" != "200" ]; then
    HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/healthz || echo "failed")
fi

if [ "$HEALTH_STATUS" != "200" ]; then
    echo "ERROR: Health check returned HTTP $HEALTH_STATUS! Deployment verification failed."
    exit 1
fi

# Observability metrics smoke check (non-blocking)
METRICS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/metrics || echo "failed")
if [ "$METRICS_STATUS" = "200" ]; then
    echo ">>> Observability metrics verified: /metrics returned HTTP 200 OK."
fi

echo "========================================================================"
echo ">>> Deployment successfully verified! HTTP 200 OK from /healthz."
echo ">>> Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "========================================================================"
