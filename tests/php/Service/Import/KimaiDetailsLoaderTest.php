<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\KimaiDetailsLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KimaiDetailsLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=internal-secret');
    }

    protected function tearDown(): void
    {
        putenv('KIMAI_API_BASE_URL');
        putenv('KIMAI_API_TOKEN');
    }

    public function testListFieldsAvoidRedundantDetailRequests(): void
    {
        $http = new MockHttpClient(static function () {
            self::fail('No HTTP request should be necessary.');
        });
        $rows = [['id' => 1, 'timeBudget' => 0, 'vatId' => '123']];
        self::assertSame($rows, new KimaiDetailsLoader($http)->enrich($rows, 'customers', ['timeBudget', 'vatId']));
    }

    public function testOnlyMissingDetailsAreRequested(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            $calls[] = [$method, $url];
            return new MockResponse('{"timeBudget":7200,"vatId":"100"}');
        });

        $rows = new KimaiDetailsLoader($http)->enrich([
            ['id' => 1, 'timeBudget' => 0, 'vatId' => 'abc'],
            ['id' => 2, 'name' => 'B'],
        ], 'customers', ['timeBudget', 'vatId']);

        self::assertCount(1, $calls);
        self::assertSame(7200, $rows[1]['timeBudget']);
        self::assertSame('100', $rows[1]['vatId']);
    }
}
