# ── Stage 1: Composer dependencies ──────────────────────────
FROM composer:2.7 AS composer-stage

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

COPY . .
RUN find src -type d -name "DataFixtures" -exec rm -rf {} + 2>/dev/null || true
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ── Stage 2: Production PHP-FPM ────────────────────────────
FROM php:8.2-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    icu-dev \
    libpq-dev \
    libzip-dev \
    oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
        opcache \
    && rm -rf /var/cache/apk/*

# PHP production config
COPY .docker/php/php.prod.ini /usr/local/etc/php/conf.d/99-production.ini

# Entrypoint (runs migrations + JWT decode at startup)
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Application code
WORKDIR /var/www/html
COPY --from=composer-stage /app .

# Create required directories and set permissions
RUN mkdir -p var/cache var/log config/jwt \
    && chown -R www-data:www-data var/ public/ \
    && chmod -R 777 var/

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
