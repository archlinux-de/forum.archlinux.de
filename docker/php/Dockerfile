FROM php:8-fpm-alpine

RUN apk add optipng

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions opcache pdo_mysql gd exif imagick

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN echo -e "opcache.enable_cli=1\nopcache.jit_buffer_size=128M" > $PHP_INI_DIR/conf.d/opcache.ini