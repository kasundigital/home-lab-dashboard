FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY app/public/ /var/www/html/
COPY app/src/ /var/www/src/
COPY VERSION /var/www/VERSION
COPY docker-entrypoint.sh /usr/local/bin/homelab-entrypoint

RUN chmod +x /usr/local/bin/homelab-entrypoint \
    && mkdir -p /var/www/data \
    && chown -R www-data:www-data /var/www/data /var/www/html /var/www/src

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/homelab-entrypoint"]
CMD ["apache2-foreground"]
