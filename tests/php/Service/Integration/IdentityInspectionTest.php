<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Provider\Akaunting\Request as AkauntingRequest;
use App\Service\Integration\AkauntingContactGateway;
use App\Service\Integration\CooperativeSources;
use App\Service\Nextcloud\ProfileFieldsClient;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IdentityInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=fixture-only');
        putenv('AKAUNTING_COMPANY_ID=1');
        putenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY=tax_number');
    }

    protected function tearDown(): void
    {
        foreach (['KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN', 'AKAUNTING_COMPANY_ID',
            'PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY'] as $key) {
            putenv($key);
        }
    }

    private function sources(string $fiscal = '52998224725', ?string $linkedKimai = 'renata.demetrio'): CooperativeSources
    {
        $logger = new NullLogger();
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/api/users/39', $url);
            return new MockResponse((string) json_encode([
                'id' => 39, 'alias' => 'Renata Demétrio', 'username' => 'renata',
                'email' => 'renata@example.test', 'enabled' => true,
                'apiToken' => 'DO-NOT-SEND', 'preferences' => ['password' => 'private'],
                'systemAccount' => false,
            ]));
        });
        $profile = $this->createStub(ProfileFieldsClient::class);
        $profile->method('userSummary')->willReturn([
            'uid' => 'renata.demetrio', 'display_name' => 'Renata Demétrio',
            'email' => 'renata@example.test', 'enabled' => true,
        ]);
        $profile->method('getFieldsForUser')->willReturn([
            'kimai_user_id' => '39', 'tax_number' => $fiscal,
            'dependents' => '0', 'weight' => '2.25',
        ]);
        $profile->method('userExists')->willReturn(true);
        $profile->method('findUserUidByLink')->willReturnCallback(
            static fn (string $key): ?string => $key === 'kimai_user_id' ? $linkedKimai : null
        );
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id' => '35', 'company_id' => '1', 'type' => 'vendor',
            'name' => 'Renata Demétrio', 'tax_number' => '529.982.247-25',
            'currency_code' => 'BRL', 'email' => 'private@example.test',
        ]);
        return new CooperativeSources(
            $http,
            $logger,
            $profile,
            new AkauntingContactGateway(new AkauntingRequest($logger, $http), $db),
            $this->createStub(Connection::class)
        );
    }

    public function testNextcloudReadOnlyIdentityIsNotTheWholeProfile(): void
    {
        $result = $this->sources()->inspectIdentity('nextcloud', 'renata.demetrio');
        self::assertSame('Renata Demétrio', $result['name']);
        self::assertSame('renata.demetrio', $result['username']);
        self::assertSame('renata@example.test', $result['email']);
        self::assertSame('Kimai #39', $result['linked_account']);
        self::assertSame('', $result['tax_hint']);
        self::assertArrayNotHasKey('tax_number', $result);
        self::assertArrayNotHasKey('weight', $result);
        self::assertArrayNotHasKey('dependents', $result);
    }

    public function testKimaiIdShowsActualAliasUsernameAndLinkNotApiToken(): void
    {
        $result = $this->sources()->inspectIdentity('kimai', '39');
        self::assertSame('Renata Demétrio', $result['name']);
        self::assertSame('renata', $result['username']);
        self::assertSame('renata.demetrio', $result['linked_account']);
        self::assertSame('renata@example.test', $result['email']);
        self::assertSame('Usuário', $result['kind']);
        self::assertArrayNotHasKey('apiToken', $result);
        self::assertArrayNotHasKey('preferences', $result);
    }

    public function testAkauntingIdentityMasksTaxNumberAndConfirmsOnlyExactFiscalMatch(): void
    {
        $result = $this->sources()->inspectIdentity('akaunting', '35', 'renata.demetrio');
        self::assertSame('Renata Demétrio', $result['name']);
        self::assertSame('Fornecedor', $result['kind']);
        self::assertSame('CPF final 725', $result['tax_hint']);
        self::assertSame('match', $result['tax_match']);
        self::assertSame('', $result['email']);
        self::assertArrayNotHasKey('tax_number', $result);
        self::assertStringNotContainsString('52998224725', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private@example.test', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDivergentFiscalIdIsExplicitlySignaled(): void
    {
        $result = $this->sources('03998224725')->inspectIdentity('akaunting', '35', 'renata.demetrio');
        self::assertSame('different', $result['tax_match']);
        self::assertSame('CPF final 725', $result['tax_hint']);
    }

    public function testMissingFiscalIdDoesNotFalselyReportExactMatch(): void
    {
        self::assertSame('missing', $this->sources('')->inspectIdentity('akaunting', '35', 'renata.demetrio')['tax_match']);
    }

    public function testUnexpectedSourceAndIdCannotTriggerAnArbitraryLookup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sources()->inspectIdentity('other-service', '35');
    }

    public function testNonNumericIdentifierIsRejectedForFinancialContact(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sources()->inspectIdentity('akaunting', '../secret');
    }
}
