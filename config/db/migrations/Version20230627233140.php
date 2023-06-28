<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Users';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('users');
        $table->addColumn('alias', 'string', ['length' => 60]);
        $table->addColumn('kimai_username', 'string', ['length' => 180]);
        $table->addColumn('akaunting_contact_id', 'bigint', ['notnull' => false]);
        $table->addColumn('tax_number', 'string', ['length' => 20]);
        $table->addColumn('dependents', 'smallint');
        $table->addColumn('health_insurance', 'float');
        $table->addColumn('enabled', 'smallint');
        $table->addColumn('metadata', 'json');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}
