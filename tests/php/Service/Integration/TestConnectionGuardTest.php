<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\TestConnectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestConnectionGuardTest extends TestCase
{
    #[DataProvider('localHosts')]
    public function testAcceptsOnlyKnownLocalDatabaseHosts(string $host): void
    {
        TestConnectionGuard::assertDatabaseHost($host, 'akaunting');
        self::assertTrue(true);
    }

    /** @return array<string, array{string}> */
    public static function localHosts(): array
    {
        return [
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv6 loopback' => ['::1'],
            'Docker local database' => ['mysql'],
            'Docker host bridge' => ['host.docker.internal'],
        ];
    }

    #[DataProvider('remoteHosts')]
    public function testRejectsRemoteDatabaseHosts(string $host): void
    {
        $this->expectException(RuntimeException::class);
        TestConnectionGuard::assertDatabaseHost($host, 'akaunting');
    }

    /** @return array<string, array{string}> */
    public static function remoteHosts(): array
    {
        return [
            'production domain' => ['akaunting.librecode.coop'],
            'remote IP' => ['203.0.113.10'],
            'unspecified host' => [''],
            'lookalike domain' => ['localhost.example.com'],
        ];
    }

    public function testAcceptsLocalHttpApi(): void
    {
        TestConnectionGuard::assertHttpTarget('http://akaunting:8080');
        TestConnectionGuard::assertHttpTarget('https://localhost:8443');
        self::assertTrue(true);
    }

    #[DataProvider('remoteUrls')]
    public function testRejectsRemoteOrInvalidHttpApi(string $url): void
    {
        $this->expectException(RuntimeException::class);
        TestConnectionGuard::assertHttpTarget($url);
    }

    /** @return array<string, array{string}> */
    public static function remoteUrls(): array
    {
        return [
            'live API' => ['https://akaunting.librecode.coop/api'],
            'remote IP' => ['https://203.0.113.10'],
            'wrong scheme' => ['file://localhost/etc/passwd'],
        ];
    }
}
