FROM php:8.4-fpm-alpine

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN apk add --no-cache git

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions opcache apcu intl pdo_mysql gd exif
RUN IPE_DONT_ENABLE=1 install-php-extensions xdebug-stable

COPY --from=composer /usr/bin/composer /usr/bin/composer

ADD https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-Country-Test.mmdb /usr/share/GeoIP/GeoLite2-Country.mmdb
RUN chmod 644 /usr/share/GeoIP/GeoLite2-Country.mmdb

RUN echo -e "error_reporting = E_ALL & ~E_DEPRECATED" > $PHP_INI_DIR/conf.d/error_reporting.ini
RUN echo -e "memory_limit = 256M" > $PHP_INI_DIR/conf.d/memory_limit.ini
