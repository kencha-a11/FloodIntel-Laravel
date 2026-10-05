#!/bin/sh
set -e

envsubst '${PORT}' < /var/www/html/docker/nginx/default.conf.template > /etc/nginx/conf.d/default.conf

php artisan config:cache
php artisan view:cache
php artisan migrate --force

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -n -c /var/www/html/docker/supervisor/supervisord.conf
