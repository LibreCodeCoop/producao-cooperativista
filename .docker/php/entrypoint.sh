#!/bin/bash

# Wait for database
php /var/www/scripts/wait-for-db.php

# Start PHP-FPM
php -S 0.0.0.0:80 -t /app/public/
