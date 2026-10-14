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
git reset --hard HEAD
git fetch origin "$TARGET_BRANCH"
git checkout -f "$TARGET_BRANCH"
git reset --hard "origin/$TARGET_BRANCH"

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

# Sync & configure Nginx with SSL and domain automation
if [ -f "$APP_DIR/ops/nginx/render-config.py" ] && [ -d /etc/nginx/sites-available ]; then
    echo ">>> Configuring Nginx with domain and SSL automation..."

    # Read APP_DOMAIN and SSL_EMAIL from .env if present
    APP_DOMAIN=""
    SSL_EMAIL=""
    if [ -f "$APP_DIR/.env" ]; then
        APP_DOMAIN=$(grep -E '^\s*APP_DOMAIN=' "$APP_DIR/.env" | cut -d '=' -f2- | tr -d ' "\r\47' || true)
        SSL_EMAIL=$(grep -E '^\s*SSL_EMAIL=' "$APP_DIR/.env" | cut -d '=' -f2- | tr -d ' "\r\47' || true)
        if [ -z "$APP_DOMAIN" ]; then
            BASE_URL_VAL=$(grep -E '^\s*BASE_URL=' "$APP_DIR/.env" | cut -d '=' -f2- | tr -d ' "\r\47' || true)
            if [[ "$BASE_URL_VAL" =~ ^https?:// ]]; then
                APP_DOMAIN=$(echo "$BASE_URL_VAL" | sed -E -e 's|^https?://||' -e 's|/.*$||' -e 's|:.*$||')
            fi
        fi
    fi

    TARGET_CONF="/etc/nginx/sites-available/corpquiz.conf"
    TMP_CONF=$(mktemp)

    # Check if a non-local domain is configured
    is_real_domain=false
    if [ -n "$APP_DOMAIN" ] && [ "$APP_DOMAIN" != "localhost" ] && [ "$APP_DOMAIN" != "127.0.0.1" ] && [ "$APP_DOMAIN" != "quiz.corp.local" ]; then
        is_real_domain=true
    fi

    ssl_cert="/etc/letsencrypt/live/$APP_DOMAIN/fullchain.pem"
    cert_exists=false
    if [ "$is_real_domain" = true ] && $SUDO test -f "$ssl_cert" 2>/dev/null; then
        cert_exists=true
    fi

    if [ "$is_real_domain" = true ] && [ "$cert_exists" = false ]; then
        # Step A: Deploy HTTP config first so ACME challenge can complete
        echo ">>> Initial setup: configuring HTTP virtual host for $APP_DOMAIN..."
        python3 "$APP_DIR/ops/nginx/render-config.py" --template "$APP_DIR/ops/nginx/corpquiz.conf" --domain "$APP_DOMAIN" --ssl-mode off --output "$TMP_CONF"
        $SUDO cp "$TMP_CONF" "$TARGET_CONF"
        if $SUDO nginx -t >/dev/null 2>&1; then
            $SUDO systemctl reload nginx
        fi

        # Step B: Request initial Let's Encrypt certificate via Certbot webroot (with nginx plugin fallback)
        if command -v certbot >/dev/null 2>&1; then
            echo ">>> Requesting Let's Encrypt certificate for $APP_DOMAIN..."
            EMAIL_FLAG="--register-unsafely-without-email"
            if [ -n "$SSL_EMAIL" ]; then
                EMAIL_FLAG="--email $SSL_EMAIL"
            fi
            $SUDO certbot certonly --webroot -w "$APP_DIR/public" -d "$APP_DOMAIN" --non-interactive --agree-tos $EMAIL_FLAG || \
            $SUDO certbot --nginx -d "$APP_DOMAIN" --non-interactive --agree-tos $EMAIL_FLAG --redirect || {
                echo "WARNING: Certbot certificate issuance failed. Serving via HTTP for now."
            }

            if $SUDO test -f "$ssl_cert" 2>/dev/null; then
                cert_exists=true
            fi
        else
            echo "NOTICE: Certbot is not installed. To enable HTTPS, install certbot with: sudo apt install -y certbot"
        fi
    fi

    # Step C: Render final configuration with explicit SSL mode
    SSL_MODE="off"
    if [ "$is_real_domain" = true ] && [ "$cert_exists" = true ]; then
        SSL_MODE="force"
    fi

    python3 "$APP_DIR/ops/nginx/render-config.py" --template "$APP_DIR/ops/nginx/corpquiz.conf" --app-dir "$APP_DIR" ${APP_DOMAIN:+--domain "$APP_DOMAIN"} --ssl-mode "$SSL_MODE" --output "$TMP_CONF"

    # Only replace if different
    if ! cmp -s "$TMP_CONF" "$TARGET_CONF" 2>/dev/null; then
        $SUDO cp "$TMP_CONF" "$TARGET_CONF"
        if $SUDO nginx -t >/dev/null 2>&1; then
            $SUDO systemctl reload nginx
            echo ">>> Nginx configuration updated & reloaded successfully."
        else
            echo "WARNING: Nginx configuration test failed! Please check $TARGET_CONF"
        fi
    else
        echo ">>> Nginx configuration is already up-to-date."
    fi
    rm -f "$TMP_CONF"
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
$SUDO chown -R "$(whoami):www-data" "$APP_DIR" 2>/dev/null || true
$SUDO chown -R www-data:www-data "$APP_DIR/storage" 2>/dev/null || true
$SUDO chmod -R 775 "$APP_DIR/storage" 2>/dev/null || true
$SUDO chmod -R 775 "$APP_DIR/public" 2>/dev/null || true

echo ">>> [7/7] Verifying application health endpoint..."
# Try health check with Host header first, then fallback to direct IP
HEALTH_HOST="${APP_DOMAIN:-quiz.corp.local}"
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: $HEALTH_HOST" http://127.0.0.1/healthz || echo "failed")
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
