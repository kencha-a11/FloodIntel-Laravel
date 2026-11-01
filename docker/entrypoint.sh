#!/bin/sh
set -e

envsubst '${PORT}' < /var/www/html/docker/nginx/default.conf.template > /etc/nginx/conf.d/default.conf

php artisan config:cache
php artisan view:cache
php artisan storage:link || true

if [ -z "$DB_OWNER_URL" ]; then
    echo "DB_OWNER_URL is not set; migrations must run under the owner credential (D-61)." >&2
    exit 1
fi

php artisan migrate --force --database=pgsql_owner

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -n -c /var/www/html/docker/supervisor/supervisord.conf
