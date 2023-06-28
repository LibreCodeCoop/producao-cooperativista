<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Transactions';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('transactions');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('type', 'string', ['length' => 50]);
        $table->addColumn('paid_at', 'date');
        $table->addColumn('transaction_of_month', 'string');
        $table->addColumn('amount', 'float');
        $table->addColumn('currency_code', 'string', ['length' => 14]);
        $table->addColumn('reference', 'string');
        $table->addColumn('nfse', 'text');
        $table->addColumn('tax_number', 'string');
        $table->addColumn('customer_reference', 'string');
        $table->addColumn('contact_id', 'bigint');
        $table->addColumn('contact_reference', 'string');
        $table->addColumn('contact_name', 'string');
        $table->addColumn('contact_type', 'string');
        $table->addColumn('category_id', 'bigint');
        $table->addColumn('category_name', 'string');
        $table->addColumn('category_type', 'string');
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('transactions');
    }
}
