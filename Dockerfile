FROM php:8.3-cli

# Dépendances système pour les extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        default-libmysqlclient-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        libpng-dev \
        libgd-dev \
        unzip \
        git \
        curl \
    && docker-php-ext-install pdo_sqlite pdo_mysql mbstring xml curl bcmath zip gd \
    && rm -rf /var/lib/apt/lists/*

# Node.js (pour npm run build)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Le volume monté depuis l'hôte Windows n'appartient pas à root → autoriser git
RUN git config --global --add safe.directory /var/www/html
