FROM php:8.2-fpm

RUN apt-get update

RUN apt-get install -y \
    git \
    libzip-dev \
    procps \
    unzip \
    && docker-php-ext-install zip

# Install sockets
RUN docker-php-ext-install sockets

# Install MySQL
RUN docker-php-ext-configure mysqli -with-mysqli=mysqlnd \
    && docker-php-ext-configure pdo_mysql -with-pdo-mysql=mysqlnd \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Install xdebug
RUN pecl install xdebug-3.2.0 \
    && docker-php-ext-enable xdebug

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY config/php.ini /usr/local/etc/php/conf.d/

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "bash", "/usr/local/bin/entrypoint.sh" ]