# syntax=docker/dockerfile:1

### Stage 1: Frontend assets
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

### Stage 2: Application runtime
FROM php:8.5-fpm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        gettext-base \
        libpq-dev \
        nginx \
        supervisor \
        unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist

COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && printf '\nclear_env = no\ncatch_workers_output = yes\n' >> /usr/local/etc/php-fpm.d/www.conf \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["sh", "/var/www/html/docker/entrypoint.sh"]
