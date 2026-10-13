# TuVocalia - PHP 8.5 (FPM) aislado para el VPS del ERP
# Imagen oficial de PHP + las extensiones que usa el sistema (imagenes y Excel).
FROM php:8.5-fpm
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j2 gd zip \
 && rm -rf /var/lib/apt/lists/* \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php-tuvocalia.ini /usr/local/etc/php/conf.d/zz-tuvocalia.ini
COPY fpm-tuvocalia.conf /usr/local/etc/php-fpm.d/zz-tuvocalia.conf
