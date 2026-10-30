<?php

declare(strict_types=1);

namespace App\Tests\Provider\Akaunting;

use App\Provider\Akaunting\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('AKAUNTING_API_BASE_URL=https://accounting.example.test');
        putenv('AKAUNTING_AUTH_USER=person@example.test');
        putenv('AKAUNTING_AUTH_TOKEN=private-token');
    }

    protected function tearDown(): void
    {
        putenv('AKAUNTING_API_BASE_URL');
        putenv('AKAUNTING_AUTH_USER');
        putenv('AKAUNTING_AUTH_TOKEN');
    }

    public function testBasicAuthenticationNeverAppearsInLogs(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message . json_encode($context);
            }
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('GET', $method);
            self::assertSame('https://accounting.example.test/api/documents', $url);
            // PHP HttpClient converts Basic Auth to the authorization header.
            $headers = implode(' ', $options['headers'] ?? []);
            self::assertStringContainsString('Basic ', $headers);
            return new MockResponse('{"data":[],"links":{"next":null}}');
        });
        $provider = new Request($logger, $http);
        self::assertSame([], $provider->send('/api/documents', method: 'GET')['data']);
        self::assertStringNotContainsString('private-token', implode(' ', $logger->records));
        self::assertStringNotContainsString('person@example.test', implode(' ', $logger->records));
    }

    public function testAuthenticationErrorReturnsActionableWithoutCredentials(): void
    {
        $provider = new Request(
            new \Psr\Log\NullLogger(),
            new MockHttpClient(new MockResponse('{"message":"token wrong"}', ['http_code' => 401]))
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('autenticação');
        $provider->send('/api/documents', method: 'GET');
    }

    public function testDocumentNumberConflictRemainsRecoverable(): void
    {
        $provider = new Request(
            new \Psr\Log\NullLogger(),
            new MockHttpClient(new MockResponse('{"errors":{"document_number":["taken"]}}', ['http_code' => 422]))
        );

        self::assertArrayHasKey('document_number', $provider->send('/api/documents')['errors']);
    }

    public function testRateLimitedReadRecoversWithoutRepeatingWrites(): void
    {
        $attempts = 0;
        $http = new MockHttpClient(function () use (&$attempts) {
            ++$attempts;
            return $attempts === 1
                ? new MockResponse('{"message":"limited"}', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']])
                : new MockResponse('{"data":[{"id":1}]}');
        });
        $provider = new Request(new \Psr\Log\NullLogger(), $http);
        self::assertSame([['id' => 1]], $provider->send('/api/documents', method: 'GET')['data']);
        self::assertSame(2, $attempts);
    }

    public function testRateLimitedWriteIsNeverRetried(): void
    {
        $attempts = 0;
        $http = new MockHttpClient(function () use (&$attempts) {
            ++$attempts;
            return new MockResponse('{"message":"limited"}', ['http_code' => 429]);
        });
        $provider = new Request(new \Psr\Log\NullLogger(), $http);
        try {
            $provider->send('/api/documents', ['name' => 'example'], method: 'POST');
            self::fail('A rate-limited write must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('429', $error->getMessage());
        }
        self::assertSame(1, $attempts);
    }

    public function testReadWithLongRetryAfterFailsWithoutExcessiveWaiting(): void
    {
        $attempts = 0;
        $http = new MockHttpClient(function () use (&$attempts) {
            ++$attempts;
            return new MockResponse('{"message":"limited"}', ['http_code' => 429, 'response_headers' => ['Retry-After: 60']]);
        });
        $provider = new Request(new \Psr\Log\NullLogger(), $http);
        try {
            $provider->send('/api/documents', method: 'GET');
            self::fail('A long rate-limit window must fail explicitly.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('429', $error->getMessage());
        }
        self::assertSame(1, $attempts);
    }

    public function testRejectsSchemeDowngradeOnPagination(): void
    {
        $provider = new Request(new \Psr\Log\NullLogger(), new MockHttpClient([]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora do servidor');
        $provider->send('http://accounting.example.test/api/documents?page=2', method: 'GET');
    }

    public function testRejectsPaginationToExternalServer(): void
    {
        $provider = new Request(new \Psr\Log\NullLogger(), new MockHttpClient([]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora do servidor');
        $provider->send('https://not-akaunting.example.test/leak', method: 'GET');
    }
}
