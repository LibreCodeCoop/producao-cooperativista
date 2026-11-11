<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\Kimai;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class KimaiPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=private-token');
    }

    protected function tearDown(): void
    {
        putenv('KIMAI_API_BASE_URL');
        putenv('KIMAI_API_TOKEN');
    }

    public function testPaginationUsesKimaiTotalPagesEvenForShortPages(): void
    {
        $http = new MockHttpClient([
            new MockResponse('[{"id":11}]', [
                'response_headers' => ['X-Total-Pages: 2', 'X-Per-Page: 50'],
            ]),
            new MockResponse('[{"id":12}]', [
                'response_headers' => ['X-Total-Pages: 2', 'X-Per-Page: 50'],
            ]),
        ]);

        self::assertSame([['id' => 11], ['id' => 12]], $this->reader($http)->doRequestKimai('/timesheets'));
    }

    public function testFirstPageNotFoundNeverBecomesEmptySnapshot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');
        $this->reader(new MockHttpClient(new MockResponse('Not found', ['http_code' => 404])))
            ->doRequestKimai('/timesheets');
    }

    public function testAuthorizationFailurePreservesExistingDataByThrowing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');
        $this->reader(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 401])))
            ->doRequestKimai('/timesheets');
    }

    public function testDetailResponseDoesNotBecomePaginatedCollection(): void
    {
        $this->assertSame(['preferences' => [['name' => 'foo', 'value' => 'bar']]], $this->reader(
            new MockHttpClient(new MockResponse('{"preferences":[{"name":"foo","value":"bar"}]}'))
        )->doRequestKimai('/users/12'));
    }

    public function testIncompleteCollectionHasActionableError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('página vazia');
        $this->reader(new MockHttpClient([
            new MockResponse('[{"id":11}]', ['response_headers' => ['X-Total-Pages: 3']]),
            new MockResponse('[]', ['response_headers' => ['X-Total-Pages: 3']]),
        ]))->doRequestKimai('/timesheets');
    }

    private function reader(HttpClientInterface $http): object
    {
        return new class ($http) {
            use Kimai;

            public NullLogger $logger;

            public function __construct(private HttpClientInterface $client)
            {
                $this->logger = new NullLogger();
            }

            protected function getKimaiHttpClient(): HttpClientInterface
            {
                return $this->client;
            }
        };
    }
}
