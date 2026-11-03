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

final class CooperativeContactProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=fixture-only');
        putenv('AKAUNTING_API_BASE_URL=https://akaunting.example.test');
        putenv('AKAUNTING_AUTH_USER=test-user');
        putenv('AKAUNTING_AUTH_TOKEN=fixture-only');
        putenv('AKAUNTING_COMPANY_ID=1');
        putenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY=tax_number');
    }

    protected function tearDown(): void
    {
        foreach ([
            'KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN', 'AKAUNTING_API_BASE_URL',
            'AKAUNTING_AUTH_USER', 'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID',
            'PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY',
        ] as $key) {
            putenv($key);
        }
    }

    /** @param array<string,mixed>|null $profileValues
     *  @param list<array<string,mixed>> $matchingContacts
     */
    private function source(?array $profileValues, array $matchingContacts = [], bool $createAllowed = false, bool $linked = true): CooperativeSources
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($createAllowed): MockResponse {
            if (str_contains($url, '/users/')) {
                return new MockResponse((string) json_encode([
                    'id' => 40, 'username' => 'luis.souza', 'alias' => 'Luis Souza',
                    'enabled' => true, 'systemAccount' => false,
                ]));
            }
            if ($method === 'POST' && $createAllowed && str_contains($url, '/api/contacts')) {
                return new MockResponse((string) json_encode(['data' => [
                    'id' => 90, 'company_id' => 1, 'type' => 'vendor',
                    'tax_number' => '52998224725',
                ]]));
            }
            throw new \LogicException('Unexpected live request.');
        });
        $profile = $this->createMock(ProfileFieldsClient::class);
        $profile->method('findByKimaiId')->willReturn(!$linked || $profileValues === null ? null : [
            'uid' => 'luis.souza', 'values' => $profileValues,
        ]);
        $profile->expects(self::never())->method('updateUserField');
        $profile->method('userExists')->willReturn(true);
        $profile->method('getFieldsForUser')->willReturn($profileValues ?? []);
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn($matchingContacts);
        $db->method('fetchOne')->willReturn(1);
        $db->method('fetchAssociative')->willReturn([
            'id' => 90, 'company_id' => 1, 'type' => 'vendor',
            'name' => 'Luiz Amorim', 'tax_number' => '52998224725',
        ]);
        return new CooperativeSources(
            $http,
            new NullLogger(),
            $profile,
            new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), $http), $db),
            $this->createStub(Connection::class),
        );
    }

    public function testEligibleMemberProposalUsesNextcloudFiscalSourceAndMasksTax(): void
    {
        $source = $this->source([
            'kimai_user_id' => '40', 'akaunting_contact_id' => '',
            'tax_number' => '529.982.247-25',
        ]);
        self::assertSame([
            'status' => 'available', 'uid' => 'luis.souza',
            'name' => 'Luis Souza', 'tax_hint' => 'CPF final 725', 'contacts' => [],
        ], $source->contactProvisioning(40, 'luis.souza'));
    }

    public function testExistingContactMustBeSelectedInsteadOfBeingCreated(): void
    {
        $source = $this->source([
            'kimai_user_id' => '40', 'tax_number' => '52998224725',
        ], [['id' => 73, 'name' => 'Luiz Amorim', 'type' => 'vendor']]);
        $proposal = $source->contactProvisioning(40, 'luis.souza');
        self::assertSame('existing', $proposal['status']);
        self::assertSame(73, $proposal['contacts'][0]['id']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('existente');
        $source->createContactForMember(40, 'luis.souza', 'Luis Souza');
    }

    public function testCreationRejectsUnconfirmedIdentity(): void
    {
        $source = $this->source(['kimai_user_id' => '40', 'tax_number' => '52998224725']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('outra conta Nextcloud');
        $source->createContactForMember(40, 'someone-else', 'Luis Souza');
    }

    public function testCreationAllowsReviewedContactNameWithoutChangingKimaiOrNextcloud(): void
    {
        $source = $this->source([
            'kimai_user_id' => '40', 'akaunting_contact_id' => '', 'tax_number' => '52998224725',
        ], createAllowed: true);
        self::assertSame(
            ['id' => 90, 'name' => 'Luiz Amorim'],
            $source->createContactForMember(40, 'luis.souza', 'Luiz Amorim')
        );
    }

    public function testUnlinkedNextcloudIdentityCannotCreateFinancialContact(): void
    {
        $source = $this->source(null, createAllowed: true, linked: false);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('CPF/CNPJ');
        $source->createContactForMember(40, 'luis.souza', 'Luis Souza');
    }

    public function testFirstTimeCooperadoCanInspectAndCreateWithoutPreexistingKimaiLink(): void
    {
        $source = $this->source([
            'kimai_user_id' => '', 'akaunting_contact_id' => '',
            'tax_number' => '529.982.247-25',
        ], createAllowed: true, linked: false);
        $proposal = $source->contactProvisioning(40, 'luis.souza');
        self::assertSame('available', $proposal['status']);
        self::assertSame('luis.souza', $proposal['uid']);
        self::assertSame('CPF final 725', $proposal['tax_hint']);
        self::assertSame(
            ['id' => 90, 'name' => 'Luiz Amorim'],
            $source->createContactForMember(40, 'luis.souza', 'Luiz Amorim')
        );
    }

    public function testFirstTimeCooperadoCannotOverwriteAnotherKimaiId(): void
    {
        $source = $this->source([
            'kimai_user_id' => '27', 'akaunting_contact_id' => '',
            'tax_number' => '52998224725',
        ], createAllowed: true, linked: false);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('outro usuário Kimai');
        $source->contactProvisioning(40, 'luis.souza');
    }

    public function testProposalNeedsSelectedNextcloudAccount(): void
    {
        $source = $this->source([
            'kimai_user_id' => '', 'tax_number' => '52998224725',
        ], linked: false);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Selecione uma conta Nextcloud');
        $source->contactProvisioning(40, '');
    }

    public function testLinkedFinancialContactPreventsSecondCreation(): void
    {
        $source = $this->source([
            'kimai_user_id' => '40', 'akaunting_contact_id' => '35', 'tax_number' => '52998224725',
        ], createAllowed: true);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('já tem');
        $source->createContactForMember(40, 'luis.souza', 'Luis Souza');
    }
}
