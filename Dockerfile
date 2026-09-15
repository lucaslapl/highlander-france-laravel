FROM php:8.3-cli

# Dépendances système pour les extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        libpng-dev \
        libgd-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_sqlite sqlite3 mbstring xml curl bcmath zip gd \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
