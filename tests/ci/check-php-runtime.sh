#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

if [ "$#" -ne 2 ] || [ -z "$1" ] || [ -z "$2" ]; then
    echo 'Usage: check-php-runtime.sh <expected-php-version> <image>' >&2
    exit 2
fi

expected=$1
image=$2
actual=$(docker run --rm --entrypoint php "$image" -r 'echo PHP_VERSION;')
case "$actual" in
    "$expected"|"$expected".*) ;;
    *) printf 'Built PHP %s instead of %s\n' "$actual" "$expected" >&2; exit 1 ;;
esac

# The monthly ETL uses BusinessDay/easter_days() for payroll date rules.
docker run --rm --entrypoint php "$image" -r '
    if (!extension_loaded("calendar") || !function_exists("easter_days") || easter_days(2026) !== 15) {
        fwrite(STDERR, "PHP calendar extension is missing or unusable.\n");
        exit(1);
    }
'
