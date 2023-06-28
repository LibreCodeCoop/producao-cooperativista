<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Invoices';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('invoices');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('akaunting_id', 'bigint');
        $table->addColumn('type', 'string', ['length' => 50]);
        $table->addColumn('issued_at', 'date');
        $table->addColumn('due_at', 'date');
        $table->addColumn('transaction_of_month', 'string');
        $table->addColumn('amount', 'float');
        $table->addColumn('currency_code', 'string', ['length' => 14]);
        $table->addColumn('document_number', 'string');
        $table->addColumn('nfse', 'text', ['notnull' => false]);
        $table->addColumn('tax_number', 'string', ['notnull' => false]);
        $table->addColumn('customer_reference', 'string', ['notnull' => false]);
        $table->addColumn('contact_id', 'bigint');
        $table->addColumn('contact_reference', 'string', ['notnull' => false]);
        $table->addColumn('contact_name', 'string');
        $table->addColumn('contact_type', 'string', ['notnull' => false]);
        $table->addColumn('category_id', 'bigint');
        $table->addColumn('category_name', 'string');
        $table->addColumn('category_type', 'string');
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['akaunting_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoices');
    }
}
