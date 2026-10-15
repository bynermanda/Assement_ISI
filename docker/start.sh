#!/bin/sh
# Container start on Render: listen on $PORT, warm the view cache, run Apache.
# Deliberately NO "php artisan migrate" or "db:seed": the app runs on the live Supabase database as it is.
set -e

PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set: add it in the Render dashboard (Environment)." >&2
    exit 1
fi

php artisan view:cache || true
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
