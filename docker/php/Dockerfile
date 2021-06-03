FROM php:8-fpm-alpine

RUN apk add optipng git

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions opcache pdo_mysql gd exif imagick intl

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN echo -e "opcache.enable_cli=1\nopcache.jit_buffer_size=128M" > $PHP_INI_DIR/conf.d/opcache.ini

ADD https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-Country-Test.mmdb /usr/share/GeoIP/GeoLite2-Country.mmdb
RUN chmod 644 /usr/share/GeoIP/GeoLite2-Country.mmdb
