FROM php:8.1-fpm-alpine

RUN apk add --no-cache git

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions opcache pdo_mysql gd exif intl

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN echo -e "error_reporting = E_ALL & ~E_DEPRECATED" > $PHP_INI_DIR/conf.d/error_reporting.ini

ADD https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-Country-Test.mmdb /usr/share/GeoIP/GeoLite2-Country.mmdb
RUN chmod 644 /usr/share/GeoIP/GeoLite2-Country.mmdb
