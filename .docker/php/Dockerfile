FROM php:8.4-fpm

RUN apt-get update

RUN apt-get install -y \
    git \
    libzip-dev \
    procps \
    unzip

# Install PHP extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod uga+x /usr/local/bin/install-php-extensions && sync \
    && install-php-extensions \
    gd \
    intl \
    mysqli \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    sockets \
    xdebug \
    zip \
    @composer \
    && rm /usr/local/bin/install-php-extensions

COPY config/php.ini /usr/local/etc/php/conf.d/

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "bash", "/usr/local/bin/entrypoint.sh" ]