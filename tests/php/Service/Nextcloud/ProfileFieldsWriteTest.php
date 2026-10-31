<?php

declare(strict_types=1);

namespace App\Tests\Service\Nextcloud;

use App\Service\Nextcloud\ProfileFieldsClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProfileFieldsWriteTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nc.example.org');
        putenv('PROFILE_FIELDS_AUTH_USER=service');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=local-fixture');
    }

    protected function tearDown(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL');
        putenv('PROFILE_FIELDS_AUTH_USER');
        putenv('PROFILE_FIELDS_AUTH_TOKEN');
    }

    public function testUpdatesOnlyTheConfiguredDefinitionAndReloadsTheActualValue(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, parse_url($url, PHP_URL_PATH)];
            $data = [];
            if (str_ends_with($url, '/cloud/users/member-01')) {
                $data = ['id' => 'member-01'];
            } elseif (str_ends_with($url, '/definitions')) {
                $data = [['id' => 2, 'field_key' => 'weight', 'type' => 'number', 'active' => true]];
            } elseif (str_ends_with($url, '/values/2') && $method === 'PUT') {
                self::assertSame(['value' => 2.5], json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR));
                $data = ['id' => 3, 'field_definition_id' => 2, 'value' => ['value' => 2.5]];
            } elseif (str_ends_with($url, '/users/member-01/values')) {
                $data = [['field_definition_id' => 2, 'value' => ['value' => 2.5]]];
            } else {
                self::fail('Unexpected route or method ' . $method . ' ' . $url);
            }
            return new MockResponse((string) json_encode(['ocs' => [
                'meta' => ['statuscode' => 200],
                'data' => $data,
            ]]));
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        $client->updateUserField('member-01', 'weight', 2.5);
        self::assertSame(['weight' => 2.5], $client->getFieldsForUser('member-01'));
        self::assertSame(['GET', 'GET', 'PUT', 'GET', 'GET'], array_column($requests, 0));
    }

    public function testCannotCreateOrphanedProfileValuesForUnknownUid(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode(['ocs' => [
                'meta' => ['statuscode' => 404],
                'data' => [],
            ]]), ['http_code' => 404]));
        $client = new ProfileFieldsClient($http, new NullLogger());
        $this->expectException(\DomainException::class);
        $client->updateUserField('missing-account', 'weight', 2.5);
    }

    public function testRejectsUnapprovedFieldWithoutNetworkAccess(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('Unapproved field reached the network.');
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $client->updateUserField('member-01', 'hourly_rate', 900.0);
    }

    public function testResolvesAnExistingAlternateUidThroughUniqueCpfLookup(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/users/lookup')) {
                self::assertSame('POST', $method);
                self::assertSame([
                    'fieldKey' => 'tax_number',
                    'fieldValue' => '00000000000',
                ], json_decode($options['body'], true));
                return new MockResponse((string) json_encode(['ocs' => [
                    'meta' => ['statuscode' => 200],
                    'data' => ['user_uid' => 'nc-other'],
                ]]));
            }
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/cloud/users/nc-other', $url);
            return new MockResponse((string) json_encode(['ocs' => [
                'meta' => ['statuscode' => 200],
                'data' => ['id' => 'nc-other'],
            ]]));
        });
        $client = new ProfileFieldsClient($http, new NullLogger());
        self::assertSame('nc-other', $client->findUserUidByTaxNumber('00000000000'));
    }
}
