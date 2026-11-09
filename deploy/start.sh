#!/bin/sh
set -eu
: "${APP_KEY:?Set a persistent APP_KEY before starting}"
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan config:clear
php artisan migrate --force
if [ "${CTE_BOOTSTRAP_ADMIN:-false}" = "true" ]; then
    php artisan cte:initialize-test
fi
php artisan config:cache
php artisan view:cache
exec apache2-foreground
