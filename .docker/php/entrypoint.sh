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

# Source files are bind-mounted, while Composer uses an authoritative classmap.
# A Git update can add a PHP class without updating vendor/composer, making
# authenticated reports fail with HTTP 500 despite a successful FPM restart.
# Rebuild only the autoload map (no dependency updates, plugins or scripts),
# as the runtime user, before FPM handles requests.
if [ -f /app/vendor/autoload.php ]; then
    if ! su -s /bin/sh -c         'cd /app && composer dump-autoload --optimize --classmap-authoritative --no-scripts --no-plugins --no-interaction'         www-data; then
        echo 'Composer authoritative autoload refresh failed; PHP-FPM will not start.' >&2
        exit 1
    fi
fi

# Wait for database
php /var/www/scripts/wait-for-db.php

# Start PHP-FPM
php-fpm
