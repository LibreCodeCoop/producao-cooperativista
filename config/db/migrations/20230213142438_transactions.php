<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Transactions extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('transactions');
        $users
            ->addColumn('type', 'string', ['limit' => 50])
            ->addColumn('paid_at', 'date')
            ->addColumn('amount', 'double')
            ->addColumn('currency_code', 'string', ['limit' => 14])
            ->addColumn('reference', 'string')
            ->addColumn('contact_id', 'biginteger')
            ->addColumn('tax_number', 'string')
            ->addColumn('contact_reference', 'string')
            ->addColumn('contact_name', 'string')
            ->addColumn('contact_type', 'string')
            ->addColumn('category_id', 'biginteger')
            ->addColumn('category_name', 'string')
            ->addColumn('category_type', 'string')
            ->addColumn('metadata', 'text')
            ->create();
    }
}
