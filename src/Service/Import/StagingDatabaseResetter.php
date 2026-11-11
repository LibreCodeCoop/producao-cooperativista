<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Rebuilds the ETL *working data* without dropping tables, migrations or logs.
 *
 * Never connect this class to the accounting entity manager. The target
 * database must be explicitly acknowledged at deployment time.
 */
final class StagingDatabaseResetter
{
    public function __construct(
        private Connection $main,
        private Connection $akaunting,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, int> Deleted records per ETL table
     */
    public function reset(): array
    {
        $expected = trim((string) getenv('PRODUCAO_STAGING_DATABASE'));
        $actual = (string) $this->main->getDatabase();
        $accounting = (string) $this->akaunting->getDatabase();

        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException(
                'Banco de trabalho não confirmado. Configure PRODUCAO_STAGING_DATABASE com o nome exato do banco informado em DATABASE_URL.'
            );
        }
        if ($accounting === '' || strcasecmp($actual, $accounting) === 0
            || $this->main === $this->akaunting) {
            throw new RuntimeException(
                'Operação bloqueada: o banco de trabalho não pode ser o banco financeiro do Akaunting.'
            );
        }

        // Refuse to delete from an unexpected schema, even if an administrator
        // typed the confirmation. This is an ETL reset, never a schema reset.
        $existing = $this->main->createSchemaManager()->listTableNames();
        $missing = array_diff(StagingSchema::TABLES, $existing);
        if ($missing !== []) {
            throw new RuntimeException(
                'O esquema de transformação está incompleto. Confira as migrações antes de limpar os dados importados.'
            );
        }

        $deleted = $this->main->transactional(function (Connection $connection): array {
            $results = [];
            foreach (StagingSchema::TABLES as $table) {
                $results[$table] = (int) $connection->executeStatement(
                    'DELETE FROM ' . $connection->quoteIdentifier($table)
                );
            }

            return $results;
        });
        $this->entityManager->clear();

        return $deleted;
    }
}
