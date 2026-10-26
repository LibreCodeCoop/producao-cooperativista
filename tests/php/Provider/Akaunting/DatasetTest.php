<?php

declare(strict_types=1);

namespace App\Tests\Provider\Akaunting;

use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatasetTest extends TestCase
{
    public function testMalformedPageHasActionableError(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('send')->willReturn(['message' => 'Something went wrong']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('campo data ausente');
        (new Dataset($request))->list('/api/documents');
    }

    public function testPaginatesWithoutDuplicatingSearchParameters(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))->method('send')->willReturnCallback(
            function (string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array {
                self::assertSame('GET', $method);
                if ($endpoint === '/api/documents') {
                    self::assertSame(['search' => 'type:bill'], $query);
                    return ['data' => [['id' => 1]], 'links' => ['next' => 'https://accounting.example.test/api/documents?page=2']];
                }
                self::assertSame([], $query);
                return ['data' => [['id' => 2]], 'links' => ['next' => null]];
            }
        );

        self::assertSame([['id' => 1], ['id' => 2]], (new Dataset($request))->list(
            '/api/documents',
            ['search' => 'type:bill']
        ));
    }

    public function testCyclesFailBeforeInfiniteLoop(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('send')->willReturn(['data' => [], 'links' => ['next' => '/api/documents']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('circular');
        (new Dataset($request))->list('/api/documents');
    }
}
