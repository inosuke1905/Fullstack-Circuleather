# Match the application's tested PHP series and install its required extensions.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/circuleather.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/circuleather.conf
COPY Circuleather/ /var/www/html/circuleather/
COPY docker/create-admin.php /opt/circuleather/create-admin.php

# Only photos need to be writable by Apache; Docker persists this directory.
RUN mkdir -p /var/www/html/circuleather/uploads/batches \
    && chown -R www-data:www-data /var/www/html/circuleather/uploads
