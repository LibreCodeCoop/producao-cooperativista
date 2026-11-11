<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProductionWebConfigTest extends TestCase
{
    public function testPublicAssetsResolveWithinPublicDirectory(): void
    {
        $base = (string) file_get_contents(dirname(__DIR__, 3) . '/.docker/web/nginx.conf');
        $production = (string) file_get_contents(dirname(__DIR__, 3) . '/.docker/web/nginx.production.conf');

        foreach ([$base, $production] as $config) {
            self::assertStringContainsString('root /app/public;', $config);
            self::assertStringContainsString('try_files $uri =404;', $config);
            self::assertStringContainsString('SCRIPT_FILENAME $document_root$fastcgi_script_name;', $config);
            self::assertStringContainsString('fastcgi_pass php:9000;', $config);
            self::assertStringNotContainsString('$document_root/public$fastcgi_script_name', $config);
        }

        self::assertStringContainsString('auth_basic "LibreCode";', $production);
        self::assertStringContainsString('auth_basic_user_file /etc/nginx/auth/users;', $production);
        self::assertStringNotContainsString('auth_basic_user_file', $base);
    }

    public function testProductionOverlayMountsAuthOutsidePublicDocumentRoot(): void
    {
        $root = dirname(__DIR__, 3);
        $config = Yaml::parseFile($root . '/docker-compose.production.yml');

        self::assertTrue($config['networks']['mysql']['external']);
        self::assertTrue($config['networks']['reverse-proxy']['external']);

        $nginxVolumes = $config['services']['nginx']['volumes'];
        self::assertContains('./.docker/web/nginx.production.conf:/etc/nginx/conf.d/default.conf:ro', $nginxVolumes);
        self::assertContains('./.htpasswd:/etc/nginx/auth/users:ro', $nginxVolumes);
        self::assertNotContains('./.htpasswd:/app/public/.htpasswd', $nginxVolumes);
        self::assertContains('reverse-proxy', $config['services']['nginx']['networks']);

        $phpVolumes = $config['services']['php']['volumes'];
        self::assertContains('./.docker/php/config/php.production.ini:/usr/local/etc/php/conf.d/99-production.ini:ro', $phpVolumes);
        self::assertContains('mysql', $config['services']['php']['networks']);
        self::assertSame('off', $config['services']['php']['environment']['XDEBUG_MODE']);
    }

    public function testCredentialsAreExcludedFromGit(): void
    {
        $ignore = (string) file_get_contents(dirname(__DIR__, 3) . '/.gitignore');
        self::assertMatchesRegularExpression('/^\.env$/m', $ignore);
        self::assertMatchesRegularExpression('/^\.htpasswd$/m', $ignore);
    }
}
