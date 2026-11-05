<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Config\RuntimeConfig;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase
{
    public function testAtomicPersistenceAndOptimisticVersion(): void
    {
        $root = sys_get_temp_dir() . '/runtime-config-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/var', 0700, true);
        $config = new RuntimeConfig($root);

        try {
            self::assertSame(['version' => 0, 'values' => []], $config->snapshot());
            self::assertSame(1, $config->save(['KIMAI_API_TOKEN' => 'test-secret'], 0));
            self::assertSame(1, $config->snapshot()['version']);
            self::assertSame('test-secret', $config->snapshot()['values']['KIMAI_API_TOKEN']);
            self::assertSame(0600, fileperms($root . '/var/admin-config/settings.json') & 0777);

            try {
                $config->save(['KIMAI_API_TOKEN' => 'outdated'], 0);
                self::fail('Concurrent stale version accepted.');
            } catch (DomainException) {
                self::assertSame('test-secret', $config->snapshot()['values']['KIMAI_API_TOKEN']);
            }
            self::assertSame(2, $config->save(['PERCENTUAL_MAXIMO' => '25'], 1));
            self::assertSame('test-secret', $config->snapshot()['values']['KIMAI_API_TOKEN']);
            self::assertSame('25', $config->snapshot()['values']['PERCENTUAL_MAXIMO']);
        } finally {
            @unlink($root . '/var/admin-config/settings.json');
            @unlink($root . '/var/admin-config/settings.lock');
            @rmdir($root . '/var/admin-config');
            @rmdir($root . '/var');
            @rmdir($root);
        }
    }
}
