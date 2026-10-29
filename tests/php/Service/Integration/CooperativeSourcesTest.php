<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Provider\Akaunting\Request as AkauntingRequest;
use App\Service\Integration\CooperativeSources;
use App\Service\Nextcloud\ProfileFieldsClient;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CooperativeSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.org/api');
        putenv('KIMAI_API_TOKEN=secret-for-test');
    }

    protected function tearDown(): void
    {
        putenv('KIMAI_API_BASE_URL');
        putenv('KIMAI_API_TOKEN');
    }

    private function sources(MockHttpClient $http): CooperativeSources
    {
        $logger = new NullLogger();
        return new CooperativeSources(
            $http,
            $logger,
            new ProfileFieldsClient($http, $logger),
            new AkauntingRequest($logger, $http),
            $this->createStub(Connection::class),
        );
    }

    public function testRosterNeverExposesKimaiTokensOrOtherPrivateAttributes(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
                ['id' => 2, 'username' => 'member-b', 'alias' => 'Bruna', 'apiToken' => 'sensitive'],
                ['id' => 1, 'username' => 'member-a', 'alias' => 'Ana', 'apiToken' => 'secret-value'],
            ])));
        $roster = $this->sources($http)->users();
        self::assertSame([1, 2], array_column($roster, 'id'));
        self::assertSame('Ana', $roster[0]['alias']);
        self::assertArrayNotHasKey('apiToken', $roster[0]);
    }

    public function testUpdatesKimaiOnlyApprovedPreferenceAndConfirmsReadback(): void
    {
        $updated = false;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$updated): MockResponse {
            if ($method === 'PATCH') {
                self::assertStringEndsWith('/users/2/preferences', $url);
                self::assertSame([['name' => 'dependents', 'value' => '3']], json_decode($options['body'], true));
                $updated = true;
                return new MockResponse('{}');
            }
            self::assertSame('GET', $method);
            return new MockResponse((string) json_encode([
                'id' => 2, 'username' => 'member-b', 'alias' => 'Bruna',
                'apiToken' => 'not-for-twig',
                'preferences' => [['name' => 'dependents', 'value' => $updated ? '3' : '0']],
            ]));
        });
        $sources = $this->sources($http);
        $sources->updateKimai(2, 'dependents', 3);
        self::assertSame('3', $sources->user(2)['preferences']['dependents']);
    }

    public function testUnsupportedKimaiFieldIsRejectedBeforeCallingApi(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('Unexpected write attempt.');
        });
        $this->expectException(\InvalidArgumentException::class);
        $this->sources($http)->updateKimai(2, 'hourly_rate', '500');
    }

    public function testAkauntingContactCannotBypassReadPermission(): void
    {
        putenv('AKAUNTING_API_BASE_URL=https://akaunting.example.org');
        putenv('AKAUNTING_AUTH_USER=service');
        putenv('AKAUNTING_AUTH_TOKEN=local-fixture');
        putenv('AKAUNTING_COMPANY_ID=1');

        $http = new MockHttpClient(static function (string $method): MockResponse {
            self::assertSame('GET', $method);
            return new MockResponse('{"message":"Forbidden","status_code":403}', ['http_code' => 403]);
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->sources($http)->updateAkauntingTaxNumber(20, '00000000000');
        } finally {
            foreach (['AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER', 'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID'] as $env) {
                putenv($env);
            }
        }
    }

    public function testAkauntingRejectsWrongCompanyBeforeAnyPut(): void
    {
        putenv('AKAUNTING_API_BASE_URL=https://akaunting.example.org');
        putenv('AKAUNTING_AUTH_USER=service');
        putenv('AKAUNTING_AUTH_TOKEN=local-fixture');
        putenv('AKAUNTING_COMPANY_ID=1');

        $http = new MockHttpClient(static function (string $method): MockResponse {
            self::assertSame('GET', $method);
            return new MockResponse('{"data":{"id":20,"company_id":3,"type":"vendor","name":"Other company"}}');
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->sources($http)->updateAkauntingTaxNumber(20, '00000000000');
        } finally {
            foreach (['AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER', 'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID'] as $env) {
                putenv($env);
            }
        }
    }
}
