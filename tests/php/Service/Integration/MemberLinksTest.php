<?php

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

final class MemberLinksTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=test-only');
        putenv('AKAUNTING_COMPANY_ID=1');
    }

    protected function tearDown(): void
    {
        foreach (['KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN', 'AKAUNTING_COMPANY_ID'] as $key) {
            putenv($key);
        }
    }

    public function testConfirmedLinksWriteOnlyHiddenFieldsAndVerifyBoth(): void
    {
        $values = [];
        $profile = $this->profileClient($values);
        $source = $this->source($profile);
        $source->linkMember(17, 'nc-account', 31);
        self::assertSame([
            'akaunting_contact_id' => '31',
            'kimai_user_id' => '17',
        ], $values);
    }

    public function testExistingKimaiLinkToAnotherUidBlocksEveryWrite(): void
    {
        $values = [];
        $profile = $this->profileClient($values, 'different-uid');
        $source = $this->source($profile);
        $this->expectException(\DomainException::class);
        try {
            $source->linkMember(17, 'nc-account', 31);
        } finally {
            self::assertSame([], $values);
        }
    }

    public function testLinkIsAllowedWhenAkauntingContactCannotBeEdited(): void
    {
        $values = [];
        $source = $this->source($this->profileClient($values), currency: null);
        $source->linkMember(17, 'nc-account', 31);
        self::assertSame('31', $values['akaunting_contact_id']);
    }

    public function testDifferentFinancialTaxNumberRejectsLinkBeforeAnyWrite(): void
    {
        $values = [];
        $source = $this->source($this->profileClient($values), contactTax: '12345678901');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('CPF/CNPJ divergente');
        try {
            $source->linkMember(17, 'nc-account', 31);
        } finally {
            self::assertSame([], $values);
        }
    }

    public function testMissingAuthoritativeProfileTaxNumberRejectsLink(): void
    {
        $values = [];
        $profile = $this->profileClient($values, profileTax: '');
        $source = $this->source($profile);
        $this->expectException(\DomainException::class);
        try {
            $source->linkMember(17, 'nc-account', 31);
        } finally {
            self::assertSame([], $values);
        }
    }

    private function profileClient(array &$values, ?string $linkedUid = null, string $profileTax = '00000000000'): ProfileFieldsClient
    {
        $client = $this->createMock(ProfileFieldsClient::class);
        $client->expects(self::once())->method('userExists')->with('nc-account')->willReturn(true);
        $client->method('activeDefinitions')->willReturn([
            'kimai_user_id' => ['id' => 4, 'type' => 'text'],
            'akaunting_contact_id' => ['id' => 5, 'type' => 'text'],
        ]);
        $client->method('getFieldsForUser')->willReturnCallback(
            static function () use (&$values, $profileTax): array {
                return ['tax_number' => $profileTax, ...$values];
            }
        );
        $client->method('findUserUidByLink')->willReturnCallback(
            static fn (string $key): ?string => $key === 'kimai_user_id' ? $linkedUid : null
        );
        $client->method('updateUserField')->willReturnCallback(
            static function (string $uid, string $key, string $value) use (&$values): void {
                self::assertSame('nc-account', $uid);
                self::assertContains($key, ['kimai_user_id', 'akaunting_contact_id']);
                $values[$key] = $value;
            }
        );

        return $client;
    }

    private function source(ProfileFieldsClient $profile, string $contactTax = '00000000000', ?string $currency = 'BRL'): CooperativeSources
    {
        $logger = new NullLogger();
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'id' => 17, 'username' => 'time-user', 'alias' => 'Example', 'systemAccount' => false,
        ])));
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id' => 31, 'company_id' => 1, 'type' => 'vendor', 'name' => 'Example',
            'tax_number' => $contactTax, 'currency_code' => $currency,
        ]);
        return new CooperativeSources(
            $http,
            $logger,
            $profile,
            new AkauntingContactGateway(new AkauntingRequest($logger, $http), $db),
            $this->createStub(Connection::class),
        );
    }
}
