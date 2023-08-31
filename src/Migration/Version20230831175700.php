<?php

declare(strict_types=1);

namespace ProducaoCooperativista\Migration;

use Doctrine\DBAL\Schema\Schema;
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
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('name', 'string');
        $table->addColumn('type', 'string', ['length' => 50]);
        $table->addColumn('enabled', 'smallint', ['default' => 1]);
        $table->addColumn('parent_id', 'bigint', ['notnull' => false]);
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('categories');
    }
}
