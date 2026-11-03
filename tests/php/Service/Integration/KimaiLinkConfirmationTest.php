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

final class KimaiLinkConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=fixture-only');
        putenv('AKAUNTING_COMPANY_ID=1');
    }

    protected function tearDown(): void
    {
        foreach (['KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN', 'AKAUNTING_COMPANY_ID'] as $key) {
            putenv($key);
        }
    }

    /** @param array<string,mixed> $values
     *  @param list<array{uid:string,key:string,value:string}> $writes
     */
    private function source(
        array &$values,
        array &$writes,
        ?string $alreadyLinkedUid = null,
        bool $uidExists = true,
        bool $safeDefinition = true,
        bool $system = false,
        bool $enabled = true,
    ): CooperativeSources {
        $logger = new NullLogger();
        $http = new MockHttpClient(static function (string $method, string $url) use ($system, $enabled): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/api/users/39', $url);
            return new MockResponse((string) json_encode([
                'id' => 39, 'username' => 'renata', 'alias' => 'Renata',
                'enabled' => $enabled, 'systemAccount' => $system,
            ]));
        });
        $profile = $this->createStub(ProfileFieldsClient::class);
        $profile->method('userExists')->willReturn($uidExists);
        $profile->method('activeDefinitions')->willReturn([
            'kimai_user_id' => [
                'id' => 4, 'type' => 'text', 'edit_policy' => $safeDefinition ? 'admins' : 'users',
                'exposure_policy' => 'hidden',
            ],
            'akaunting_contact_id' => [
                'id' => 5, 'type' => 'text', 'edit_policy' => 'admins',
                'exposure_policy' => 'hidden',
            ],
        ]);
        $profile->method('getFieldsForUser')->willReturnCallback(static function () use (&$values): array {
            return $values;
        });
        $profile->method('findUserUidByLink')->willReturnCallback(
            static function (string $key, int $id) use ($alreadyLinkedUid): ?string {
                self::assertSame('kimai_user_id', $key);
                self::assertSame(39, $id);
                return $alreadyLinkedUid;
            }
        );
        $profile->method('updateUserField')->willReturnCallback(
            static function (string $uid, string $key, string $value) use (&$values, &$writes): void {
                $writes[] = ['uid' => $uid, 'key' => $key, 'value' => $value];
                $values[$key] = $value;
            }
        );
        return new CooperativeSources(
            $http,
            $logger,
            $profile,
            new AkauntingContactGateway(new AkauntingRequest($logger, $http), $this->createStub(Connection::class)),
            $this->createStub(Connection::class),
        );
    }

    public function testMissingFinancialContactAndFiscalDataDoNotBlockTechnicalLink(): void
    {
        $values = ['tax_number' => '', 'akaunting_contact_id' => '', 'dependents' => ''];
        $writes = [];
        $source = $this->source($values, $writes);
        self::assertTrue($source->linkFieldsReady());
        $source->confirmKimaiLink(39, 'renata');
        self::assertSame([['uid' => 'renata', 'key' => 'kimai_user_id', 'value' => '39']], $writes);
        self::assertSame('', $values['akaunting_contact_id']);
        self::assertSame('', $values['tax_number']);
    }

    public function testInactiveHistoricalMemberCanBeLinkedWithoutReactivatingKimaiOrNextcloud(): void
    {
        $values = ['tax_number' => '', 'akaunting_contact_id' => '', 'dependents' => ''];
        $writes = [];
        $this->source($values, $writes, enabled: false)->confirmKimaiLink(39, 'renata');
        self::assertSame([['uid' => 'renata', 'key' => 'kimai_user_id', 'value' => '39']], $writes);
        self::assertArrayNotHasKey('enabled', $values);
        self::assertSame('', $values['akaunting_contact_id']);
        self::assertSame('', $values['tax_number']);
    }

    public function testRepeatedConfirmationDoesNotWriteAgain(): void
    {
        $values = ['kimai_user_id' => '39'];
        $writes = [];
        $this->source($values, $writes, 'renata')->confirmKimaiLink(39, 'renata');
        self::assertSame([], $writes);
    }

    public function testExistingLinkToAnotherKimaiRejectsBeforeWrite(): void
    {
        $values = ['kimai_user_id' => '25'];
        $writes = [];
        try {
            $this->source($values, $writes)->confirmKimaiLink(39, 'renata');
            self::fail('The member already belongs to another Kimai identity.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('outro usuário Kimai', $error->getMessage());
            self::assertSame([], $writes);
        }
    }

    public function testKimaiAlreadyLinkedToAnotherUidRejectsBeforeWrite(): void
    {
        $values = [];
        $writes = [];
        try {
            $this->source($values, $writes, 'someone-else')->confirmKimaiLink(39, 'renata');
            self::fail('Kimai is already linked to someone else.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('outra conta Nextcloud', $error->getMessage());
            self::assertSame([], $writes);
        }
    }

    public function testMissingNextcloudAccountFailsClosed(): void
    {
        $values = [];
        $writes = [];
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('conta Nextcloud existente');
        $this->source($values, $writes, uidExists: false)->confirmKimaiLink(39, 'renata');
    }

    public function testUnprotectedProfileDefinitionCannotBeUsed(): void
    {
        $values = [];
        $writes = [];
        $source = $this->source($values, $writes, safeDefinition: false);
        self::assertFalse($source->linkFieldsReady());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('proteção administrativa');
        $source->confirmKimaiLink(39, 'renata');
    }

    public function testServiceUsersCannotBeLinkedAsCooperados(): void
    {
        $values = [];
        $writes = [];
        $this->expectException(\DomainException::class);
        $this->source($values, $writes, system: true)->confirmKimaiLink(39, 'renata');
    }
}
