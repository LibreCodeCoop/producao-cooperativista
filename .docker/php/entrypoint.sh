#!/bin/bash

# Set uid of host machine
usermod --non-unique --uid "${HOST_UID}" www-data
groupmod --non-unique --gid "${HOST_GID}" www-data

# Wait for database
php /var/www/scripts/wait-for-db.php

# Start PHP-FPM
php-fpm
