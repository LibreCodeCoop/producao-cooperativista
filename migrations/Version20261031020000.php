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

final class Version20261031020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record every Akaunting document-write attempt without storing personal or financial payload data.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('publication_write_attempts');
        $table->addColumn('attempt_id', Types::STRING, ['length' => 32]);
        $table->addColumn('operation_key', Types::STRING, ['length' => 64]);
        $table->addColumn('attempt_number', Types::INTEGER, ['unsigned' => true]);
        $table->addColumn('company_id', Types::INTEGER, ['unsigned' => true]);
        $table->addColumn('method', Types::STRING, ['length' => 8]);
        $table->addColumn('endpoint', Types::STRING, ['length' => 96]);
        $table->addColumn('document_fingerprint', Types::STRING, ['length' => 64]);
        $table->addColumn('payload_fingerprint', Types::STRING, ['length' => 64]);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('remote_document_id', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('http_status', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['attempt_id']);
        $table->addUniqueIndex(['operation_key', 'attempt_number'], 'unique_publication_attempt');
        $table->addIndex(['state', 'started_at'], 'publication_review_queue');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Publication attempts are accounting evidence. Restore a reviewed backup instead of deleting this table.'
        );
    }
}
