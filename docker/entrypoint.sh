#!/bin/sh
set -e

# build the framework caches from the real environment of this container
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
