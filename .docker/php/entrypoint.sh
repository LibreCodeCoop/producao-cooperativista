#!/bin/bash

# Wait for database
php /var/www/scripts/wait-for-db.php

# Start PHP-FPM
php-fpm
