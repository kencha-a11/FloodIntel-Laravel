# syntax=docker/dockerfile:1

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

# The upload limits sit above config/uploads.php's ceiling (a 6 MB request,
# a 5 MB photo), so the application's own 413 and 422 answers arrive before
# PHP discards an oversized body.
#
# post_max_size must also reach nginx's client_max_body_size. When PHP's
# limit was below the edge's, a body between the two was discarded by PHP
# before the application could refuse it: the request answered 200 with an
# empty body, so an oversized upload looked accepted (DP-05, 2026-11-10).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && { \
        echo 'upload_max_filesize=6M'; \
        echo 'post_max_size=20M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini \
    && printf '\nclear_env = no\ncatch_workers_output = yes\n' >> /usr/local/etc/php-fpm.d/www.conf \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["sh", "/var/www/html/docker/entrypoint.sh"]
