#!/bin/bash

# Set uid of host machine
usermod --non-unique --uid "${HOST_UID}" www-data
groupmod --non-unique --gid "${HOST_GID}" www-data

# Root CLI commands may leave a root-owned Symfony cache, blocking PHP-FPM.
# Repair only the disposable cache, never financial records.
if [ -d /app/var/cache ]; then
    chown -R "${HOST_UID}:${HOST_GID}" /app/var/cache
    chmod -R u+rwX /app/var/cache
fi

# Wait for database
php /var/www/scripts/wait-for-db.php

# Start PHP-FPM
php-fpm
