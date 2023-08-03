<?php

declare(strict_types=1);

namespace ProducaoCooperativista\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230803185730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Taxes';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('taxes');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('name', 'string');
        $table->addColumn('rate', 'float');
        $table->addColumn('enabled', 'smallint', ['default' => 1]);
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoices');
    }
}
