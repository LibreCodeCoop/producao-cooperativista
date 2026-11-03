<?php

declare(strict_types=1);

namespace App\Tests\Provider\Akaunting;

use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DatasetTest extends TestCase
{
    public function testMalformedPageHasActionableError(): void
    {
        $request = $this->createStub(Request::class);
        $request->method('send')->willReturn(['message' => 'Something went wrong']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('campo data ausente');
        new Dataset($request)->list('/api/documents');
    }

    public function testPaginationRestoresMissingCompanyAndSearchFilters(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))->method('send')->willReturnCallback(
            function (string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array {
                self::assertSame('GET', $method);
                if ($endpoint === '/api/documents') {
                    self::assertSame(['company_id' => 1, 'search' => 'type:bill due_at>=2026-05-01'], $query);
                    return ['data' => [['id' => 1]], 'links' => ['next' => 'https://accounting.example.test/api/documents?page=2']];
                }
                self::assertSame('https://accounting.example.test/api/documents?page=2', $endpoint);
                self::assertSame(['company_id' => 1, 'search' => 'type:bill due_at>=2026-05-01'], $query);
                return ['data' => [['id' => 2]], 'links' => ['next' => null]];
            }
        );

        self::assertSame([['id' => 1], ['id' => 2]], new Dataset($request)->list(
            '/api/documents',
            ['company_id' => 1, 'search' => 'type:bill due_at>=2026-05-01']
        ));
    }

    public function testNextLinkAlreadyContainingScopeDoesNotDuplicateFilters(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))->method('send')->willReturnCallback(
            function (string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array {
                self::assertSame('GET', $method);
                if ($endpoint === '/api/documents') {
                    self::assertSame(['company_id' => 1, 'search' => 'type:bill'], $query);
                    return ['data' => [['id' => 1]], 'links' => [
                        'next' => 'https://accounting.example.test/api/documents?page=2&company_id=1&search=type%3Abill',
                    ]];
                }
                self::assertSame([], $query);
                self::assertStringContainsString('page=2', $endpoint);
                return ['data' => [['id' => 2]], 'links' => ['next' => null]];
            },
        );
        self::assertSame([['id' => 1], ['id' => 2]], new Dataset($request)->list('/api/documents', [
            'company_id' => 1, 'search' => 'type:bill',
        ]));
    }

    public function testPaginationCannotSwitchCompanyOrRemoveFilters(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())->method('send')->willReturn([
            'data' => [['id' => 1]],
            'links' => ['next' => '/api/documents?page=2&company_id=2'],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alterou os filtros ou a empresa');
        new Dataset($request)->list('/api/documents', ['company_id' => 1, 'search' => 'type:bill']);
    }

    public function testPaginationCannotSwitchSearchPeriod(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())->method('send')->willReturn([
            'data' => [['id' => 1]],
            'links' => ['next' => '/api/documents?page=2&search=type%3Ainvoice'],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alterou os filtros ou a empresa');
        new Dataset($request)->list('/api/documents', ['company_id' => 1, 'search' => 'type:bill']);
    }

    public function testOriginalPageParameterDoesNotOverrideNextPage(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))->method('send')->willReturnCallback(
            function (string $endpoint, array $body = [], array $query = []): array {
                if ($endpoint === '/api/documents') {
                    self::assertSame(['company_id' => 1, 'page' => 1], $query);
                    return ['data' => [['id' => 1]], 'links' => ['next' => '/api/documents?page=2']];
                }
                self::assertSame('/api/documents?page=2', $endpoint);
                self::assertSame(['company_id' => 1], $query);
                return ['data' => [['id' => 2]], 'links' => ['next' => null]];
            },
        );
        self::assertCount(2, new Dataset($request)->list('/api/documents', ['company_id' => 1, 'page' => 1]));
    }

    public function testRealHttpClientPreservesCompanyAndPeriodAcrossPages(): void
    {
        $variables = [
            'AKAUNTING_API_BASE_URL' => 'https://accounting.example.test',
            'AKAUNTING_AUTH_USER' => 'test-user',
            'AKAUNTING_AUTH_TOKEN' => 'test-only',
            'AKAUNTING_COMPANY_ID' => '1',
        ];
        $previous = [];
        foreach ($variables as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $pages = [];
            $client = new MockHttpClient(static function (string $method, string $url) use (&$pages): MockResponse {
                self::assertSame('GET', $method);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                self::assertSame('1', $query['company_id']);
                self::assertSame('type:bill due_at>=2026-05-01 due_at<=2026-12-31', $query['search']);
                $page = (int) ($query['page'] ?? 1);
                $pages[] = $page;
                return new MockResponse((string) json_encode([
                    'data' => [['id' => $page]],
                    'links' => ['next' => $page === 1
                        ? 'https://accounting.example.test/api/documents?page=2' : null],
                ]));
            });
            $reader = new Dataset(new Request(new NullLogger(), $client));
            self::assertSame([['id' => 1], ['id' => 2]], $reader->list('/api/documents', [
                'company_id' => 1,
                'search' => 'type:bill due_at>=2026-05-01 due_at<=2026-12-31',
            ]));
            self::assertSame([1, 2], $pages);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    public function testCyclesFailBeforeInfiniteLoop(): void
    {
        $request = $this->createStub(Request::class);
        $request->method('send')->willReturn(['data' => [], 'links' => ['next' => '/api/documents']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('circular');
        new Dataset($request)->list('/api/documents');
    }
}
