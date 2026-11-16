FROM php:8.3.36-fpm

WORKDIR /var/www

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libxml2-dev libxslt1-dev libbz2-dev \
        iproute2 default-mysql-client socat \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd mbstring exif pcntl bcmath xsl bz2 \
    && rm -rf /var/lib/apt/lists/*

COPY security.ini /usr/local/etc/php/conf.d/zz-simanis-security.ini
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/zz-simanis.conf
COPY ha-bridge-client /usr/local/bin/simanis-ha-bridge
RUN chmod 755 /usr/local/bin/simanis-ha-bridge

EXPOSE 19000
