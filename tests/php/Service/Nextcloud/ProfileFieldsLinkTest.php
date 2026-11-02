<?php

declare(strict_types=1);

namespace App\Tests\Service\Nextcloud;

use App\Service\Nextcloud\ProfileFieldsClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProfileFieldsLinkTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nextcloud.example.test');
        putenv('PROFILE_FIELDS_AUTH_USER=admin');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=test-only');
    }

    protected function tearDown(): void
    {
        foreach (['PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER', 'PROFILE_FIELDS_AUTH_TOKEN'] as $key) {
            putenv($key);
        }
    }

    public function testExactKimaiLinkReturnsProfileWithoutTaxNumberLookupOrExtraRequests(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls++;
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/users/lookup', $url);
            self::assertSame(['fieldKey' => 'kimai_user_id', 'fieldValue' => '17'], json_decode($options['body'], true));
            return self::response([
                'user_uid' => 'nc-account',
                'fields' => [
                    'kimai_user_id' => ['value' => ['value' => '17']],
                    'akaunting_contact_id' => ['value' => ['value' => '31']],
                    'weight' => ['value' => ['value' => '1.5']],
                    'dependents' => ['value' => ['value' => '0']],
                ],
            ]);
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        self::assertSame([
            'uid' => 'nc-account',
            'values' => [
                'kimai_user_id' => '17', 'akaunting_contact_id' => '31',
                'weight' => '1.5', 'dependents' => '0',
            ],
        ], $client->findByKimaiId(17));
        self::assertSame('31', $client->getFieldsForUser('nc-account')['akaunting_contact_id']);
        self::assertSame(1, $calls);
    }

    public function testPreparesExactlyTwoHiddenAdministratorFields(): void
    {
        $created = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$created): MockResponse {
            if ($method === 'GET') {
                self::assertStringEndsWith('/definitions', $url);
                return self::response([['id' => 1, 'field_key' => 'weight', 'type' => 'number', 'active' => true]]);
            }
            self::assertSame('POST', $method);
            $params = json_decode($options['body'], true);
            self::assertSame('text', $params['type']);
            self::assertSame('admins', $params['editPolicy']);
            self::assertSame('hidden', $params['exposurePolicy']);
            $created[] = $params['fieldKey'];
            return self::response(['id' => count($created) + 1]);
        });
        new ProfileFieldsClient($http, new NullLogger())->ensureLinkDefinitions();
        self::assertSame(['kimai_user_id', 'akaunting_contact_id'], $created);
    }

    public function testImporterUsesExplicitIdentifiersWithoutCpfDiscovery(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [ $method, $url ];
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 4, 'type' => 'text', 'active' => true, 'field_key' => 'kimai_user_id'],
                    ['id' => 5, 'type' => 'text', 'active' => true, 'field_key' => 'akaunting_contact_id'],
                ]);
            }
            self::assertSame('POST', $method);
            self::assertSame('kimai_user_id', json_decode($options['body'], true)['fieldKey']);
            return self::response([
                'user_uid' => 'nc-other-name',
                'fields' => [
                    'kimai_user_id' => ['value' => ['value' => '17']],
                    'akaunting_contact_id' => ['value' => ['value' => '31']],
                    'tax_number' => ['value' => ['value' => '52998224725']],
                    'weight' => ['value' => ['value' => '1.75']],
                    'dependents' => ['value' => ['value' => '0']],
                ],
            ]);
        });

        $user = new ProfileFieldsClient($http, new NullLogger())->enrichUser([
            'id' => 17, 'username' => 'time-user',
        ]);
        self::assertSame('52998224725', $user['tax_number']);
        self::assertSame(1.75, $user['peso']);
        self::assertSame(0, $user['dependents']);
        self::assertSame(31, $user['akaunting_contact_id']);
        self::assertCount(2, $requests);
        self::assertStringEndsWith('/users/lookup', $requests[1][1]);
    }

    public function testExplicitKimaiLinkDoesNotSilentlyOverwriteConflictingFiscalIdentity(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/definitions')) {
                return self::response([
                    ['id' => 4, 'field_key' => 'kimai_user_id', 'type' => 'text', 'active' => true],
                ]);
            }
            return self::response([
                'user_uid' => 'nc-account',
                'fields' => [
                    'kimai_user_id' => ['value' => ['value' => '17']],
                    'tax_number' => ['value' => ['value' => '52998224725']],
                    'dependents' => ['value' => ['value' => '0']],
                ],
            ]);
        });
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Identificador fiscal divergente');
        new ProfileFieldsClient($http, new NullLogger())->enrichUser([
            'id' => 17, 'username' => 'time-user', 'tax_number' => '11111111111',
        ]);
    }

    public function testAmbiguousLinkLookupDoesNotPickAnyUser(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::response(
            ['message' => 'Ambiguous link'],
            409,
        ));
        $this->expectException(\RuntimeException::class);
        new ProfileFieldsClient($http, new NullLogger())->findByKimaiId(17);
    }

    private static function response(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode([
            'ocs' => ['meta' => ['statuscode' => $status], 'data' => $data],
        ]), ['http_code' => $status]);
    }
}
