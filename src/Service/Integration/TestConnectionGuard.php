<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Integration;

use RuntimeException;

/** Guard testing connections against accidental production access. */
final class TestConnectionGuard
{
    private const array LOCAL_HOSTS = [
        '127.0.0.1', '::1', 'localhost', 'host.docker.internal',
        'mysql', 'mariadb', 'db', 'akaunting', 'akaunting.localhost',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Invoked by the PHPUnit bootstrap outside Psalm source scanning. */
    public static function assertDatabaseHost(string $host, string $connectionName): void
    {
        if (!self::isLocal($host)) {
            throw new RuntimeException(sprintf(
                'Unsafe PHPUnit database host for %s: tests must use local databases.',
                $connectionName
            ));
        }
    }

    public static function assertHttpTarget(string $baseUrl): void
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!is_string($host) || !is_string($scheme)
            || !in_array(strtolower($scheme), ['http', 'https'], true)
            || !self::isLocal($host)) {
            throw new RuntimeException(
                'Unsafe Akaunting test HTTP target: real integration tests must use a local API.'
            );
        }
    }

    private static function isLocal(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), self::LOCAL_HOSTS, true);
    }
}
