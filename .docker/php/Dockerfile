ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libzip-dev procps unzip \
    && rm -rf /var/lib/apt/lists/*

# Pin the extension installer rather than downloading a mutable latest binary.
COPY --from=ghcr.io/mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN install-php-extensions \
    gd intl mysqli pdo pdo_mysql pdo_pgsql pgsql sockets xdebug zip @composer \
    && rm /usr/local/bin/install-php-extensions

# Available for development, disabled by default to avoid debug overhead.
ENV XDEBUG_MODE=off

COPY config/php.ini /usr/local/etc/php/conf.d/

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "bash", "/usr/local/bin/entrypoint.sh" ]