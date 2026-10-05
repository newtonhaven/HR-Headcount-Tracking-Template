FROM composer:2 AS composer

FROM php:8.3-apache

# pdo_mysql for the database, zip + gd for the PhpSpreadsheet Excel export, unzip for Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev unzip \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Only public/ is served; src/, bin/, database/ and vendor/ stay outside the web root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf "ServerTokens Prod\nServerSignature Off\n" > /etc/apache2/conf-available/hardening.conf \
    && a2enconf hardening \
    && printf "expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n" > /usr/local/etc/php/conf.d/app.ini

COPY entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
