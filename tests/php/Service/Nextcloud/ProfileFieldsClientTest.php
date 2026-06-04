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
    protected function tearDown(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL');
        putenv('PROFILE_FIELDS_AUTH_USER');
        putenv('PROFILE_FIELDS_AUTH_TOKEN');
        putenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY');
        putenv('PROFILE_FIELDS_WEIGHT_FIELD_KEY');
    }

    public function testGetFieldsForTaxNumberMapsValuesByFieldKey(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nextcloud.example.com');
        putenv('PROFILE_FIELDS_AUTH_USER=api-user');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=api-token');

        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = compact('method', 'url', 'options');

            if (str_ends_with($url, '/users/lookup')) {
                self::assertSame('POST', $method);
                self::assertLookupPayload($options, [
                    'fieldKey' => 'tax_number',
                    'fieldValue' => '11122233344',
                ]);

                return new MockResponse((string) json_encode([
                    'ocs' => [
                        'meta' => [
                            'status' => 'ok',
                            'statuscode' => 100,
                        ],
                        'data' => [
                            'user_uid' => 'pessoa02',
                            'lookup_field_key' => 'tax_number',
                            'fields' => [
                                'tax_number' => [
                                    'definition' => [
                                        'field_key' => 'tax_number',
                                    ],
                                    'value' => [
                                        'field_definition_id' => 10,
                                        'value' => [
                                            'value' => '11122233344',
                                        ],
                                    ],
                                ],
                                'weight' => [
                                    'definition' => [
                                        'field_key' => 'weight',
                                    ],
                                    'value' => [
                                        'field_definition_id' => 11,
                                        'value' => [
                                            'value' => '2.5',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]));
            }

            return new MockResponse('not found', ['http_code' => 404]);
        });

        $client = new ProfileFieldsClient($httpClient, new NullLogger());
        $fields = $client->getFieldsForTaxNumber('11122233344');

        self::assertSame([
            'tax_number' => '11122233344',
            'weight' => '2.5',
        ], $fields);
        self::assertCount(1, $calls);
    }

    public function testGetFieldsForTaxNumberReturnsEmptyArrayWhenIntegrationIsNotConfigured(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('The Profile Fields API should not be called when the integration is not configured.');
        });

        $client = new ProfileFieldsClient($httpClient, new NullLogger());

        self::assertSame([], $client->getFieldsForTaxNumber('11122233344'));
    }

    public function testEnrichUserUsesTaxNumberLookupAndMapsPesoFromProfileFields(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nextcloud.example.com');
        putenv('PROFILE_FIELDS_AUTH_USER=api-user');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=api-token');

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/users/lookup')) {
                self::assertSame('POST', $method);
                self::assertLookupPayload($options, [
                    'fieldKey' => 'tax_number',
                    'fieldValue' => '11122233344',
                ]);

                return new MockResponse((string) json_encode([
                    'ocs' => [
                        'meta' => [
                            'status' => 'ok',
                            'statuscode' => 100,
                        ],
                        'data' => [
                            'user_uid' => 'pessoa02',
                            'lookup_field_key' => 'tax_number',
                            'fields' => [
                                'tax_number' => [
                                    'definition' => [
                                        'field_key' => 'tax_number',
                                    ],
                                    'value' => [
                                        'field_definition_id' => 10,
                                        'value' => [
                                            'value' => '11122233344',
                                        ],
                                    ],
                                ],
                                'weight' => [
                                    'definition' => [
                                        'field_key' => 'weight',
                                    ],
                                    'value' => [
                                        'field_definition_id' => 11,
                                        'value' => [
                                            'value' => '1.75',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]));
            }

            return new MockResponse('not found', ['http_code' => 404]);
        });

        $client = new ProfileFieldsClient($httpClient, new NullLogger());

        self::assertSame([
            'id' => 2,
            'username' => 'vitor@librecode.coop',
            'kimai_username' => 'vitor@librecode.coop',
            'tax_number' => '11122233344',
            'peso' => 1.75,
        ], $client->enrichUser([
            'id' => 2,
            'username' => 'vitor@librecode.coop',
            'kimai_username' => 'vitor@librecode.coop',
            'tax_number' => '11122233344',
        ]));
    }

    public function testEnrichUserDoesNotFallbackToKimaiUsernameWhenTaxNumberIsMissing(): void
    {
        putenv('PROFILE_FIELDS_API_BASE_URL=https://nextcloud.example.com');
        putenv('PROFILE_FIELDS_AUTH_USER=api-user');
        putenv('PROFILE_FIELDS_AUTH_TOKEN=api-token');

        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('Profile Fields lookup must use tax_number and should not fallback to kimai_username when it is missing.');
        });

        $client = new ProfileFieldsClient($httpClient, new NullLogger());

        self::assertSame([
            'id' => 2,
            'username' => 'pessoa02',
            'kimai_username' => 'pessoa02',
        ], $client->enrichUser([
            'id' => 2,
            'username' => 'pessoa02',
            'kimai_username' => 'pessoa02',
        ]));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $expectedPayload
     */
    private static function assertLookupPayload(array $options, array $expectedPayload): void
    {
        $payload = $options['json'] ?? $options['body'] ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        self::assertSame($expectedPayload, $payload);
    }
}
