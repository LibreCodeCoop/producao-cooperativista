<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SourceConfigurationAuthorizationTest extends WebTestCase
{
    public function testNonAdministratorsCannotChangePrivateProfileOrFinancialIdentifiers(): void
    {
        $previous = getenv('CONFIG_ADMIN_USERS');
        try {
            putenv('CONFIG_ADMIN_USERS=system-admin');
            $client = static::createClient();
            foreach (['nextcloud', 'kimai', 'akaunting'] as $source) {
                $client->request('POST', '/configuracao/cooperado/1/' . $source, [
                    'field' => 'tax_number', 'value' => 'synthetic',
                ], server: ['REMOTE_USER' => 'finance-reader']);
                self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
            }
            $client->request('POST', '/configuracao/cooperado/1/vincular', [
                'nextcloud_uid' => 'member', 'akaunting_contact_id' => '9',
            ], server: ['REMOTE_USER' => 'finance-reader']);
            self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        } finally {
            putenv($previous === false ? 'CONFIG_ADMIN_USERS' : 'CONFIG_ADMIN_USERS=' . $previous);
        }
    }

    public function testAdministrativeWritesStillRequireCsrfConfirmation(): void
    {
        $previous = getenv('CONFIG_ADMIN_USERS');
        try {
            putenv('CONFIG_ADMIN_USERS=system-admin');
            $client = static::createClient();
            $client->request('POST', '/configuracao/cooperado/1/nextcloud', [
                'field' => 'weight', 'value' => '2',
            ], server: ['REMOTE_USER' => 'system-admin']);
            self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        } finally {
            putenv($previous === false ? 'CONFIG_ADMIN_USERS' : 'CONFIG_ADMIN_USERS=' . $previous);
        }
    }
}
