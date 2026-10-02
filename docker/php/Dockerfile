FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    shadow

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    bcmath \
    pcntl \
    intl \
    opcache

# Install PECL extensions (redis, apcu)
RUN pecl install redis apcu && \
    docker-php-ext-enable redis apcu

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure OPcache and PHP settings
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.jit=tracing" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.jit_buffer_size=32M" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini

WORKDIR /var/www/html

# Create socket directory for redis and php-fpm
RUN mkdir -p /var/run/redis && chmod 777 /var/run/redis

EXPOSE 9000
CMD ["php-fpm"]
