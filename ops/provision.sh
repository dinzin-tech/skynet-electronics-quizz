#!/usr/bin/env bash
# ==============================================================================
# CorpQuiz Server Provisioning Script (Idempotent)
# Target: Ubuntu 22.04 / 24.04 LTS on Hostinger KVM 2 VPS (2 vCPU, 8 GB RAM)
# ==============================================================================
set -euo pipefail

echo ">>> [1/7] Updating package index..."
export DEBIAN_FRONTEND=noninteractive
apt-get update && apt-get install -y --no-install-recommends \
    curl git unzip nginx redis-server mysql-server \
    software-properties-common ca-certificates ufw fail2ban

echo ">>> [2/7] Installing PHP 8.2 and required extensions..."
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
    php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
    php8.2-apcu php8.2-opcache php8.2-mbstring php8.2-xml php8.2-zip php8.2-curl

echo ">>> [3/7] Installing Node.js & Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

echo ">>> [4/7] Applying Kernel Tuning & Limits..."
cp /var/www/corpquiz/ops/sysctl.d/99-corpquiz.conf /etc/sysctl.d/99-corpquiz.conf
sysctl --system

echo ">>> [5/7] Configuring Redis & MySQL..."
mkdir -p /var/lib/redis /var/log/redis
chown -R redis:redis /var/lib/redis /var/log/redis
cp /var/www/corpquiz/ops/redis/redis.conf /etc/redis/redis.conf
usermod -a -G redis www-data
systemctl restart redis-server

cp /var/www/corpquiz/ops/mysql/my.cnf /etc/mysql/conf.d/corpquiz.cnf
systemctl restart mysql

echo ">>> [6/7] Configuring PHP-FPM Pools & OPcache..."
cp /var/www/corpquiz/ops/php-fpm/hot.conf /etc/php/8.2/fpm/pool.d/hot.conf
cp /var/www/corpquiz/ops/php-fpm/auth.conf /etc/php/8.2/fpm/pool.d/auth.conf
cp /var/www/corpquiz/ops/php-fpm/main.conf /etc/php/8.2/fpm/pool.d/main.conf
cp /var/www/corpquiz/ops/php-fpm/opcache.ini /etc/php/8.2/mods-available/opcache.ini
systemctl restart php8.2-fpm

echo ">>> [7/7] Installing Systemd Services & Nginx..."
mkdir -p /var/log/corpquiz
chown -R www-data:www-data /var/log/corpquiz

cp /var/www/corpquiz/ops/systemd/*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs
systemctl restart quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs

cp /var/www/corpquiz/ops/nginx/corpquiz.conf /etc/nginx/sites-available/corpquiz.conf
ln -sf /etc/nginx/sites-available/corpquiz.conf /etc/nginx/sites-enabled/corpquiz.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo ">>> CorpQuiz server provisioned successfully!"
