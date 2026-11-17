#!/bin/sh
set -eu
cd /var/www/html

sh deploy/railway/prepare-apache.sh

: "${APP_KEY:?APP_KEY must be set and preserved across deployments}"
: "${DB_DATABASE:?DB_DATABASE must name the separate Railway database}"
: "${CTE_HOSTED_DATABASE:?CTE_HOSTED_DATABASE must authorize that exact database}"
if [ "$DB_DATABASE" != "$CTE_HOSTED_DATABASE" ]; then
    echo 'DB_DATABASE and CTE_HOSTED_DATABASE must match.' >&2
    exit 1
fi

# The persistent volume is mounted only when the container starts.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php deploy/railway/runtime-env.php
chown www-data:www-data .env
php artisan config:clear --no-interaction
php artisan package:discover --no-interaction

# Importing a private data copy is separate from building and publishing source.
# Never seed, reset users, or replace data during application startup.
if [ "${CTE_RUN_MIGRATIONS:-false}" = 'true' ]; then
    php artisan migrate --force --no-interaction
fi
if [ "${CTE_SCHEDULER_ENABLED:-false}" = 'true' ]; then
    runuser -u www-data -- php artisan schedule:work --no-interaction &
fi

exec apache2-foreground
