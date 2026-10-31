<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SystemConfigurationHttpTest extends WebTestCase
{
    public function testConfigurationMenuShowsSourcesAndPolicyWithoutCredentials(): void
    {
        $client = static::createClient();
        $client->request('GET', '/configuracao/integracoes');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Integrações e regras', $html);
        self::assertStringContainsString('href="/configuracao"', $html);
        self::assertStringContainsString('href="/configuracao/integracoes"', $html);
        self::assertStringContainsString('Nextcloud · Profile Fields', $html);
        self::assertStringContainsString('Kimai', $html);
        self::assertStringContainsString('Akaunting', $html);
        self::assertStringContainsString('Apuração cooperativista', $html);
        self::assertStringContainsString('A presença de configuração não confirma', $html);
        self::assertStringNotContainsString('PROFILE_FIELDS_AUTH_TOKEN=', $html);
        self::assertStringNotContainsString('AKAUNTING_AUTH_TOKEN=', $html);
        self::assertStringNotContainsString('KIMAI_API_TOKEN=', $html);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
