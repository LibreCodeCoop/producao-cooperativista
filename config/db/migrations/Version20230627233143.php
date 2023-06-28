<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Projects';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('projects');
        $table->addColumn('parent_title', 'string', ['length' => 150]);
        $table->addColumn('customer_id', 'bigint');
        $table->addColumn('name', 'string', ['length' => 150]);
        $table->addColumn('start', 'date');
        $table->addColumn('end', 'date');
        $table->addColumn('comment', 'text');
        $table->addColumn('visible', 'smallint');
        $table->addColumn('billable', 'smallint');
        $table->addColumn('color', 'string', ['length' => 7]);
        $table->addColumn('global_activities', 'smallint');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('projects');
    }
}
