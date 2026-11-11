#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
cat > "$work/docker" <<'DOCKER'
#!/bin/sh
set -eu
[ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = --entrypoint ] && [ "$4" = php ] && [ "$5" = test-image ] && [ "$6" = -r ]
case "$7" in
    *PHP_VERSION*) printf '%s' "$FAKE_PHP_VERSION" ;;
    *easter_days*) [ "${FAKE_CALENDAR_OK:-yes}" = yes ] ;;
    *) exit 91 ;;
esac
DOCKER
chmod +x "$work/docker"
PATH="$work:$PATH" FAKE_PHP_VERSION=8.5.12 sh tests/ci/check-php-runtime.sh 8.5 test-image
if PATH="$work:$PATH" FAKE_PHP_VERSION=8.4.9 sh tests/ci/check-php-runtime.sh 8.5 test-image >/dev/null 2>&1; then
    echo 'Wrong PHP version was accepted.' >&2
    exit 1
fi
if PATH="$work:$PATH" FAKE_PHP_VERSION=8.5.12 FAKE_CALENDAR_OK=no sh tests/ci/check-php-runtime.sh 8.5 test-image >/dev/null 2>&1; then
    echo 'Missing calendar extension was accepted.' >&2
    exit 1
fi
if PATH="$work:$PATH" FAKE_PHP_VERSION=8.5.12 sh tests/ci/check-php-runtime.sh 8.5 >/dev/null 2>&1; then
    echo 'Missing required arguments were accepted.' >&2
    exit 1
fi
echo 'PHP image verifier contract: passed'
