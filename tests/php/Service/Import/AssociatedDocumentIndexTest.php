<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\AssociatedDocumentIndex;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AssociatedDocumentIndexTest extends TestCase
{
    public function testOnlyUniquePositiveDocumentIdsAreQueried(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn([
            ['id' => 11, 'customer_reference' => 'cliente-11', 'metadata' => '{}'],
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $params, array $types) use ($result): Result {
                self::assertStringContainsString('FROM invoices', $sql);
                self::assertSame([11, 12], $params['ids']);
                self::assertSame(ArrayParameterType::INTEGER, $types['ids']);
                return $result;
            });
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        self::assertSame(
            [11 => ['id' => 11, 'customer_reference' => 'cliente-11', 'metadata' => '{}']],
            (new AssociatedDocumentIndex())->byId($manager, [11, 12, 11, 0, -1])
        );
    }

    public function testEmptyReferencesAvoidDatabaseRequests(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('getConnection');
        self::assertSame([], (new AssociatedDocumentIndex())->byId($manager, []));
    }

    public function testLargeImportsUseBoundedBatches(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('executeQuery')->willReturn($result);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        self::assertSame([], (new AssociatedDocumentIndex())->byId($manager, range(1, 401)));
    }
}
