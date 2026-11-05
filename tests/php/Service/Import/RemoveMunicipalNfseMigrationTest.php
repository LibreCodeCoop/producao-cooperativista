<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261031172000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 4) . '/migrations/Version20261031172000.php';

final class RemoveMunicipalNfseMigrationTest extends TestCase
{
    public function testEmptyImportTableMayBeRemoved(): void
    {
        $migration = $this->migrationWithRows(0);
        $migration->up(new Schema());

        self::assertSame(['DROP TABLE nfse'], array_map(
            static fn ($sql): string => $sql->getStatement(),
            $migration->getSql()
        ));
        self::assertFalse($migration->isTransactional());
    }

    public function testTableWithExistingDataCannotBeDropped(): void
    {
        $migration = $this->migrationWithRows(1);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Preserve e confira os dados');
        $migration->up(new Schema());
    }

    private function migrationWithRows(int $rows): Version20261031172000
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn(
            $this->createStub(AbstractSchemaManager::class)
        );
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT COUNT(*) FROM nfse')
            ->willReturn($rows);

        return new Version20261031172000($connection, new NullLogger());
    }
}
