<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\StagingDatabaseResetter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingDatabaseResetterTest extends TestCase
{
    private const TABLES = [
        'timesheet', 'transactions', 'invoices', 'nfse', 'taxes',
        'categories', 'users', 'projects', 'customers',
    ];

    protected function tearDown(): void
    {
        putenv('PRODUCAO_STAGING_DATABASE');
    }

    public function testOnlyTheNineStagingTablesAreClearedTransactionally(): void
    {
        putenv('PRODUCAO_STAGING_DATABASE=etl_test');

        $main = $this->createMock(Connection::class);
        $financial = $this->createMock(Connection::class);
        $main->method('getDatabase')->willReturn('etl_test');
        $financial->method('getDatabase')->willReturn('akaunting');
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn([...self::TABLES, 'doctrine_migration_versions', 'some_unrelated_table']);
        $main->method('createSchemaManager')->willReturn($schema);

        $queried = [];
        $main->method('quoteIdentifier')->willReturnCallback(
            static fn (string $table): string => '`' . $table . '`'
        );
        $main->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback): array => $callback($main));
        $main->expects($this->exactly(count(self::TABLES)))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$queried): int {
                $queried[] = $sql;
                return 2;
            });

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('clear');
        $result = (new StagingDatabaseResetter($main, $financial, $manager))->reset();

        self::assertCount(9, $result);
        self::assertSame(18, array_sum($result));
        self::assertSame(
            array_map(static fn (string $table): string => 'DELETE FROM `' . $table . '`', self::TABLES),
            $queried
        );
        self::assertNotContains('DELETE FROM `doctrine_migration_versions`', $queried);
    }

    public function testRejectsAnUnconfirmedOrFinancialDatabaseBeforeAnyDeletion(): void
    {
        foreach ([
            ['', 'etl_test', 'akaunting'],
            ['etl_test', 'akaunting', 'akaunting'],
            ['etl_test', 'etl_test', 'etl_test'],
        ] as [$expected, $actual, $financialName]) {
            putenv('PRODUCAO_STAGING_DATABASE=' . $expected);
            $main = $this->createMock(Connection::class);
            $financial = $this->createMock(Connection::class);
            $main->method('getDatabase')->willReturn($actual);
            $financial->method('getDatabase')->willReturn($financialName);
            $main->expects($this->never())->method('executeStatement');
            try {
                (new StagingDatabaseResetter(
                    $main,
                    $financial,
                    $this->createMock(EntityManagerInterface::class)
                ))->reset();
                self::fail('Dangerous target was accepted.');
            } catch (RuntimeException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testRefusesIncompleteStagingSchemaBeforeDeleting(): void
    {
        putenv('PRODUCAO_STAGING_DATABASE=etl_test');
        $main = $this->createMock(Connection::class);
        $financial = $this->createMock(Connection::class);
        $main->method('getDatabase')->willReturn('etl_test');
        $financial->method('getDatabase')->willReturn('akaunting');
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['transactions']);
        $main->method('createSchemaManager')->willReturn($schema);
        $main->expects($this->never())->method('executeStatement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('esquema de transformação está incompleto');
        (new StagingDatabaseResetter(
            $main,
            $financial,
            $this->createMock(EntityManagerInterface::class)
        ))->reset();
    }
}
