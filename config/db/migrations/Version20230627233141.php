<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Customers';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('customers');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('name', 'string', ['length' => 150]);
        $table->addColumn('number', 'string', ['length' => 50]);
        $table->addColumn('comment', 'text');
        $table->addColumn('visible', 'smallint');
        $table->addColumn('billable', 'smallint');
        $table->addColumn('currency', 'string', ['length' => 3]);
        $table->addColumn('color', 'string', ['length' => 7]);
        $table->addColumn('vat_id', 'string');
        $table->addColumn('time_budget', 'bigint');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('customers');
    }
}
