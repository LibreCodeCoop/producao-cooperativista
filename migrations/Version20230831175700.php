<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20230831175700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Categories';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('categories');
        $table->addColumn('id', Types::BIGINT, ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('type', Types::STRING, ['length' => 50]);
        $table->addColumn('enabled', Types::SMALLINT, ['default' => 1]);
        $table->addColumn('parent_id', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('metadata', Types::JSON);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('categories');
    }
}
