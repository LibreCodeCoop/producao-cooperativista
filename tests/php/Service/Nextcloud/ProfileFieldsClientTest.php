<?php

declare(strict_types=1);

namespace App\Tests\Service\Nextcloud;

use App\Service\Nextcloud\ProfileFieldsClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProfileFieldsClientTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nextcloud.example.test');
        putenv('PROFILE_FIELDS_AUTH_USER=service');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=test-only');
    }

    protected function tearDown(): void
    {
        foreach (['PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER', 'PROFILE_FIELDS_AUTH_TOKEN'] as $name) {
            putenv($name);
        }
    }

    private static function response(array $data, int $http = 200): MockResponse
    {
        return new MockResponse((string) json_encode([
            'ocs' => ['meta' => ['statuscode' => $http], 'data' => $data],
        ]), ['http_code' => $http]);
    }

    public function testUserSummaryReturnsOnlyAllowedNextcloudIdentityMetadata(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/ocs/v2.php/cloud/users/renata.demetrio', $url);
            return self::response([
                'id' => 'renata.demetrio', 'displayname' => 'Renata Demétrio',
                'email' => 'renata@example.test', 'enabled' => true,
                'storageLocation' => '/private/storage',
                'groups' => ['finance'], 'phone' => 'private-number',
            ]);
        });
        $summary = new ProfileFieldsClient($http, new NullLogger())->userSummary('renata.demetrio');
        self::assertSame([
            'uid' => 'renata.demetrio', 'display_name' => 'Renata Demétrio',
            'email' => 'renata@example.test', 'enabled' => true,
        ], $summary);
        self::assertArrayNotHasKey('storageLocation', $summary);
        self::assertArrayNotHasKey('groups', $summary);
        self::assertArrayNotHasKey('phone', $summary);
    }

    public function testNextcloudSummaryRejectsMissingOrDifferentUid(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::response([
            'id' => 'other-user', 'displayname' => 'Outra pessoa',
        ]));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não localizada');
        new ProfileFieldsClient($http, new NullLogger())->userSummary('renata.demetrio');
    }

    public function testReadsProfileFieldsByExactNextcloudUid(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 5, 'field_key' => 'tax_number', 'active' => true, 'type' => 'text'],
                    ['id' => 6, 'field_key' => 'weight', 'active' => true, 'type' => 'number'],
                ]);
            }
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/users/known-uid/values', $url);
            return self::response([
                ['field_definition_id' => 5, 'value' => ['value' => 'SYNTHETIC']],
                ['field_definition_id' => 6, 'value' => ['value' => '1.25']],
            ]);
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        self::assertSame([
            'tax_number' => 'SYNTHETIC', 'weight' => '1.25',
        ], $client->getFieldsForUser('known-uid'));
        self::assertCount(2, $requests);
        self::assertSame($client->getFieldsForUser('known-uid'), $client->getFieldsForUser('known-uid'));
        self::assertCount(2, $requests);
    }

    public function testNoCpfDiscoveryWhenKimaiIdHasNoExplicitLink(): void
    {
        $paths = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$paths): MockResponse {
            $paths[] = $url;
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 4, 'field_key' => 'kimai_user_id', 'active' => true, 'type' => 'text'],
                ]);
            }
            self::assertSame('POST', $method);
            self::assertSame('kimai_user_id', json_decode($options['body'], true)['fieldKey']);
            return self::response([]);
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        self::assertSame([
            'id' => 17, 'username' => 'unlinked', 'email' => 'native@example.test',
        ], $client->enrichUser([
            'id' => 17, 'username' => 'unlinked', 'email' => 'native@example.test',
            'tax_number' => 'legacy-ignored', 'akaunting_contact_id' => 99,
        ]));
        self::assertCount(2, $paths);
        self::assertStringEndsWith('/users/lookup', $paths[1]);
    }

    public function testMonthlyUserWithoutLinkCannotBeImportedByCpfOrUsername(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 4, 'field_key' => 'kimai_user_id', 'active' => true, 'type' => 'text'],
                ]);
            }
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/users/lookup', $url);
            return self::response([]);
        });
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('kimai_user_id explícito');
        new ProfileFieldsClient($http, new NullLogger())->enrichUser([
            'id' => 17, 'username' => 'same-username', 'tax_number' => 'synthetic',
        ], true);
    }

    public function testLinkedKimaiUserGetsFiscalIdentityOnlyFromNextcloud(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 4, 'field_key' => 'kimai_user_id', 'active' => true, 'type' => 'text'],
                ]);
            }
            self::assertSame('kimai_user_id', json_decode($options['body'], true)['fieldKey']);
            return self::response([
                'user_uid' => 'nextcloud-account',
                'fields' => [
                    'kimai_user_id' => ['value' => ['value' => '17']],
                    'akaunting_contact_id' => ['value' => ['value' => '35']],
                    'tax_number' => ['value' => ['value' => 'reference-id']],
                    'weight' => ['value' => ['value' => '2.5']],
                    'dependents' => ['value' => ['value' => '0']],
                ],
            ]);
        });
        $user = new ProfileFieldsClient($http, new NullLogger())->enrichUser([
            'id' => 17, 'username' => 'kimai-native', 'email' => 'native@example.test',
            'tax_number' => 'obsolete-kimai-value', 'akaunting_contact_id' => 999,
        ], true);
        self::assertSame('reference-id', $user['tax_number']);
        self::assertSame(35, $user['akaunting_contact_id']);
        self::assertSame(0, $user['dependents']);
        self::assertSame(2.5, $user['peso']);
        self::assertSame('native@example.test', $user['email']);
    }

    public function testDependentCountMustBeExplicitIntegerIncludingZero(): void
    {
        $client = new ProfileFieldsClient(new MockHttpClient([]), new NullLogger());
        $apply = new \ReflectionMethod(ProfileFieldsClient::class, 'applyFieldsToUser');
        self::assertSame(0, $apply->invoke($client, [], ['dependents' => '0'])['dependents']);
        $this->expectException(\DomainException::class);
        $apply->invoke($client, [], ['dependents' => '2.5']);
    }

    public function testNoConfiguredProfileFieldsNeverLeaksKimaiFiscalValues(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL');
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No request should occur without configuration.');
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        self::assertSame(['id' => 1], $client->enrichUser([
            'id' => 1, 'tax_number' => 'obsolete-1',
        ]));
    }
}
