#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
image=${1:?Usage: check-entrypoint-autoload.test.sh <built-php-image>}
tmp=$(mktemp -d)
container="pdc-autoload-smoke-$$"
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    rm -rf "$tmp"
}
trap cleanup EXIT INT TERM
chmod 755 "$tmp"
mkdir -p "$tmp/src"
cat > "$tmp/composer.json" <<'JSON'
{"name":"librecodecoop/autoload-smoke","autoload":{"psr-4":{"Demo\\":"src/"}},"config":{"classmap-authoritative":true}}
JSON
cat > "$tmp/src/Existing.php" <<'PHP'
<?php
namespace Demo;
final class Existing {}
PHP
# Simulate the classmap built before a later Git deployment.
docker run --rm -u "$(id -u):$(id -g)" -v "$tmp:/app" -w /app \
    --entrypoint composer "$image" dump-autoload --optimize \
    --classmap-authoritative --no-scripts --no-plugins --no-interaction >/dev/null
cat > "$tmp/src/NewlyAdded.php" <<'PHP'
<?php
namespace Demo;
final class NewlyAdded {}
PHP
# The authoritative classmap must NOT resolve a class added after its build.
docker run --rm -u "$(id -u):$(id -g)" -v "$tmp:/app" -w /app \
    --entrypoint php "$image" -r \
    'require "/app/vendor/autoload.php"; exit(class_exists("Demo\\NewlyAdded") ? 1 : 0);'
cat > "$tmp/wait-for-db.php" <<'PHP'
<?php
// No database is used by this isolated autoload test.
PHP
# Launch the normal entrypoint: it must refresh the map as www-data first.
docker run -d --name "$container" -e "HOST_UID=$(id -u)" -e "HOST_GID=$(id -g)" \
    -v "$tmp:/app" -v "$tmp/wait-for-db.php:/var/www/scripts/wait-for-db.php:ro" \
    "$image" >/dev/null
attempt=0
while [ "$attempt" -lt 15 ]; do
    if docker exec "$container" php -r \
        'require "/app/vendor/autoload.php"; exit(class_exists("Demo\\NewlyAdded") ? 0 : 1);' \
        2>/dev/null; then
        echo 'PASS: entrypoint refreshes a stale authoritative map before PHP-FPM starts.'
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 1
done
docker logs "$container" 2>&1 | tail -n 55
printf '%s\n' 'FAIL: NewlyAdded class is missing from authoritative Composer map.' >&2
exit 1
