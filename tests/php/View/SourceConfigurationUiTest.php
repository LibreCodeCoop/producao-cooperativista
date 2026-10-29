<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Controller\SourceConfiguration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SourceConfigurationUiTest extends TestCase
{
    public function testIntegrationLinksNeverExposeCredentialsOrPrivateIPs(): void
    {
        $method = new ReflectionMethod(SourceConfiguration::class, 'publicSourceUrl');
        self::assertSame('https://cloud.librecode.coop', $method->invoke(null, 'https://someuser:token@cloud.librecode.coop/apps/settings?secret=1'));
        self::assertNull($method->invoke(null, 'http://localhost:8080'));
        self::assertNull($method->invoke(null, 'https://192.168.0.2/admin'));
        self::assertNull($method->invoke(null, 'ftp://cloud.librecode.coop'));
    }

    public function testConfigurationScreenIsExplicitlyReadOnlyAndLinksToSources(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('configuracao.index.html.twig', [
            'nextcloud_url' => 'https://cloud.librecode.coop',
            'nextcloud_ready' => true,
            'kimai_url' => 'https://kimai.example.org',
            'kimai_ready' => false,
        ]);
        self::assertStringContainsString('Somente consulta', $html);
        self::assertStringContainsString('nextcloud', $html);
        self::assertStringContainsString('Kimai', $html);
        self::assertStringContainsString('Akaunting', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
        self::assertStringNotContainsString('method="post"', $html);
    }
}
