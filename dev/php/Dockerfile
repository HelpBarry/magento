ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-fpm-bookworm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl pdo_mysql soap xsl zip sockets opcache ftp pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip rsync procps mariadb-client ca-certificates libxml2-utils \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-magento.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN mkdir -p /var/www/html /var/www/.composer/cache \
    && chown -R www-data:www-data /var/www

ENV COMPOSER_HOME=/var/www/.composer
WORKDIR /var/www/html
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
