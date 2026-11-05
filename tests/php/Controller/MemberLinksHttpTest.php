<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MemberLinksHttpTest extends WebTestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('CONFIG_ADMIN_USERS');
        putenv('CONFIG_ADMIN_USERS=librecode');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'CONFIG_ADMIN_USERS' : 'CONFIG_ADMIN_USERS=' . $this->previous);
        parent::tearDown();
    }

    public function testAnonymousSearchIsForbidden(): void
    {
        $client = static::createClient();
        $client->request('GET', '/configuracao/vinculos/opcoes?source=akaunting&q=ab');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testNonAdministratorsCannotInspectOrCreateFinancialContacts(): void
    {
        $client = static::createClient();
        $client->request('POST', '/configuracao/cooperado/39/vincular-kimai', [
            'kimai_user_id' => '39', 'nextcloud_uid' => 'renata',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $client->request('GET', '/configuracao/vinculos/conferir?source=nextcloud&id=renata.demetrio');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $client->request('GET', '/configuracao/cooperado/40/contato/proposta');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $client->request('POST', '/configuracao/cooperado/40/contato/criar', [
            'contact_name' => 'Pessoa Teste', 'identity_confirmed' => '1',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testAdministratorShortSearchAvoidsExternalSources(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/configuracao/vinculos/opcoes?source=akaunting&q=a',
            server: ['REMOTE_USER' => 'librecode']
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(['items' => []], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->request(
            'GET',
            '/configuracao/vinculos/opcoes?source=other&q=ab',
            server: ['REMOTE_USER' => 'librecode']
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testIdentityInspectionRejectsInvalidSourcesWithoutAccessingExternalAccounts(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/configuracao/vinculos/conferir?source=malicious&id=39',
            server: ['REMOTE_USER' => 'librecode']
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAllMutatingLinkEndpointsRequireCsrf(): void
    {
        $client = static::createClient();
        $client->request('POST', '/configuracao/vinculos/preparar', server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $client->request('POST', '/configuracao/cooperado/39/vincular-kimai', [
            'kimai_user_id' => '39', 'nextcloud_uid' => 'renata',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $client->request('POST', '/configuracao/cooperado/17/vincular', [
            'nextcloud_uid' => 'any-uid', 'akaunting_contact_id' => '31',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $client->request('POST', '/configuracao/cooperado/17/contato/criar', [
            'nextcloud_uid' => 'any-uid', 'contact_name' => 'Pessoa de teste',
            'identity_confirmed' => '1',
        ], server: ['REMOTE_USER' => 'librecode']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }
}
