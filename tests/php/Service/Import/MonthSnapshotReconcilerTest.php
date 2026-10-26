<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\MonthSnapshotReconciler;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MonthSnapshotReconcilerTest extends TestCase
{
    public function testRemovesOnlyEntriesMissingFromTheSourceMonth(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([10, 20, 30]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $params) use ($result): Result {
                self::assertStringContainsString('FROM timesheet', $sql);
                self::assertSame('2026-05-01 00:00:00', $params['start']);
                self::assertSame('2026-06-01 00:00:00', $params['end']);
                return $result;
            });
        $connection->expects($this->once())->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params, array $types): int {
                self::assertStringContainsString('DELETE FROM timesheet', $sql);
                self::assertSame([20, 30], $params['ids']);
                self::assertSame(ArrayParameterType::INTEGER, $types['ids']);
                return 2;
            });
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        self::assertSame(2, (new MonthSnapshotReconciler())->removeMissingTimesheets(
            $manager,
            new DateTimeImmutable('2026-05-24'),
            [10]
        ));
    }

    public function testNoDeletionForIdenticalSnapshot(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([12, 13]);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects($this->never())->method('executeStatement');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        self::assertSame(0, (new MonthSnapshotReconciler())->removeMissingTimesheets(
            $manager,
            new DateTimeImmutable('2026-05-01'),
            [12, 13]
        ));
    }

    public function testAnEmptyAuthoritativeMonthDeletesStaleRows(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([7]);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects($this->once())->method('executeStatement')
            ->with(
                self::stringContains('DELETE FROM timesheet'),
                ['ids' => [7]],
                ['ids' => ArrayParameterType::INTEGER]
            )->willReturn(1);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        self::assertSame(1, (new MonthSnapshotReconciler())->removeMissingTimesheets(
            $manager,
            new DateTimeImmutable('2026-04-01'),
            []
        ));
    }
}
