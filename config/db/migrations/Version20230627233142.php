<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Timesheet';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('timesheet');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('activity_id', 'bigint', ['unsigned' => true]);
        $table->addColumn('project_id', 'bigint');
        $table->addColumn('user_id', 'bigint');
        $table->addColumn('begin', 'datetime');
        $table->addColumn('end', 'datetime');
        $table->addColumn('duration', 'bigint');
        $table->addColumn('description', 'text');
        $table->addColumn('rate', 'float');
        $table->addColumn('internalRate', 'float');
        $table->addColumn('exported', 'smallint');
        $table->addColumn('billable', 'smallint');
        $table->addUniqueIndex(['activity_id']);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('timesheet');
    }
}
