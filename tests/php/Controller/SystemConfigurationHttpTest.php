<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SystemConfigurationHttpTest extends WebTestCase
{
    private string|false $previousAdmins;

    protected function setUp(): void
    {
        $this->previousAdmins = getenv('CONFIG_ADMIN_USERS');
        putenv('CONFIG_ADMIN_USERS=librecode');
    }

    protected function tearDown(): void
    {
        putenv($this->previousAdmins === false ? 'CONFIG_ADMIN_USERS' : 'CONFIG_ADMIN_USERS=' . $this->previousAdmins);
        parent::tearDown();
    }

    public function testAnonymousUserCannotInspectIntegrationConfiguration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/configuracao/integracoes');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testAdministratorSeesEditableSettingsButNotSecretValues(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/configuracao/integracoes', server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nextcloud · Profile Fields', $html);
        self::assertStringContainsString('KIMAI_API_TOKEN', $html);
        self::assertStringContainsString('data-reveal-key="KIMAI_API_TOKEN"', $html);
        self::assertStringContainsString('data-admin-settings-group', $html);
        self::assertStringContainsString('data-token=', $html);
        self::assertStringContainsString('href="/configuracao/cooperados"', $html);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNotEmpty($crawler->filter('#admin-settings')->attr('data-token'));
    }

    public function testBrandModeIsAnExplicitChoiceAndRejectsInvalidValues(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/configuracao/integracoes', server: ['REMOTE_USER' => 'librecode']);
        $select = $crawler->filter('select#config-APP_BRAND_DISPLAY_MODE');
        self::assertCount(1, $select);
        self::assertSame('logo_and_text', $select->attr('data-initial-value'));
        self::assertSame([
            'logo_and_text', 'logo_only', 'text_only',
        ], $select->filter('option')->each(static fn ($option): ?string => $option->attr('value')));
        $token = $crawler->filter('#admin-settings')->attr('data-token');
        $version = $crawler->filter('#admin-settings')->attr('data-version');

        foreach (['invalid', ''] as $invalid) {
            $client->request('POST', '/configuracao/integracoes/salvar', [
                '_token' => $token, 'version' => $version, 'group' => '0',
                'changes' => json_encode(['APP_BRAND_DISPLAY_MODE' => $invalid], JSON_THROW_ON_ERROR),
            ], server: ['REMOTE_USER' => 'librecode']);
            self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        }
    }

    public function testRevealRequiresCsrfAndAllowsOnlySecretKeys(): void
    {
        $client = static::createClient();
        $client->request('POST', '/configuracao/integracoes/revelar', [
            'key' => 'KIMAI_API_TOKEN',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $crawler = $client->request('GET', '/configuracao/integracoes', server: ['REMOTE_USER' => 'librecode']);
        $token = $crawler->filter('#admin-settings')->attr('data-token');
        $client->request('POST', '/configuracao/integracoes/revelar', [
            '_token' => $token, 'key' => 'PERCENTUAL_MAXIMO',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testSecretsStayOutOfHtmlAndRequireExplicitAuthorizedReveal(): void
    {
        $previous = getenv('KIMAI_API_TOKEN');
        putenv('KIMAI_API_TOKEN=synthetic-test-only');

        try {
            $client = static::createClient();
            $crawler = $client->request(
                'GET',
                '/configuracao/integracoes',
                server: ['REMOTE_USER' => 'librecode']
            );
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('synthetic-test-only', $client->getResponse()->getContent());

            $client->request('POST', '/configuracao/integracoes/revelar', [
                '_token' => $crawler->filter('#admin-settings')->attr('data-token'),
                'key' => 'KIMAI_API_TOKEN',
            ], server: ['REMOTE_USER' => 'librecode']);

            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
            self::assertSame(['value' => 'synthetic-test-only'], json_decode($client->getResponse()->getContent(), true));
            self::assertStringContainsString(
                'no-store',
                (string) $client->getResponse()->headers->get('Cache-Control')
            );
        } finally {
            putenv($previous === false ? 'KIMAI_API_TOKEN' : 'KIMAI_API_TOKEN=' . $previous);
        }
    }

    public function testSaveRejectsUnlistedKeysWithoutWriting(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/configuracao/integracoes', server: ['REMOTE_USER' => 'librecode']);
        $token = $crawler->filter('#admin-settings')->attr('data-token');
        $client->request('POST', '/configuracao/integracoes/salvar', [
            '_token' => $token, 'version' => '0', 'group' => '1',
            'changes' => '{"PHP_INI_SCAN_DIR":"unexpected"}',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }
}
