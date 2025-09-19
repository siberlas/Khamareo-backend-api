FROM php:8.2-fpm

# Installe les dépendances système nécessaires à Symfony
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
        opcache

# Installer Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
