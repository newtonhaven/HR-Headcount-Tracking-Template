#!/bin/sh
set -e

# The project folder is mounted at /var/www/html; install the PHP dependencies on first start.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "Installing PHP dependencies (composer install)..."
    COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir=/var/www/html \
        --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
fi

exec docker-php-entrypoint "$@"
