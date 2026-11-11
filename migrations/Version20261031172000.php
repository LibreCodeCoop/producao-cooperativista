<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261031172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the unused municipal NFS-e import table from the ETL database only when empty.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nfse') !== 0,
            'A tabela de importação municipal contém registros. Preserve e confira os dados antes de removê-la.'
        );

        $this->addSql('DROP TABLE nfse');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('O importador municipal não integra mais a aplicação.');
    }
}
