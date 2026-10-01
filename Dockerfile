FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY app/ /var/www/
COPY VERSION /var/www/VERSION

RUN mkdir -p /var/www/data \
    && chown -R www-data:www-data /var/www/data /var/www/html

EXPOSE 80
