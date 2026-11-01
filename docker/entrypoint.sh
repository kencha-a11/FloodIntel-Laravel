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

# Seeding must follow the migration (the spatial column the geometry sync writes
# to is created there) and is idempotent, so every boot reconciles the 12
# authoritative barangays instead of leaving a fresh deploy with none (OPS-17).
php artisan db:seed --class=BarangaySeeder --force --database=pgsql_owner

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -n -c /var/www/html/docker/supervisor/supervisord.conf
