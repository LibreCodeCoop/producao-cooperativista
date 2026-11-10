<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261110173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember source revenue amounts already allocated by production competence, independently from payment date.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('production_income_appropriations');
        $table->addColumn('company_id', Types::INTEGER, ['unsigned' => true]);
        $table->addColumn('source_type', Types::STRING, ['length' => 12]);
        $table->addColumn('source_id', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('competence', Types::STRING, ['length' => 7]);
        $table->addColumn('amount_cents', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('source_total_cents', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('revision', Types::STRING, ['length' => 64]);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['company_id', 'source_type', 'source_id', 'competence'], 'primary_appropriation');
        $table->addIndex(['company_id', 'state', 'competence'], 'review_appropriation');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Apropriações confirmadas são evidências financeiras. Restaure um backup auditado para desfazer.'
        );
    }
}
