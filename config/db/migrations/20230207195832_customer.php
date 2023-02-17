<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Customer extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('customers');
        $users
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('number', 'string', ['limit' => 50])
            ->addColumn('comment', 'text')
            ->addColumn('visible', 'smallinteger')
            ->addColumn('billable', 'smallinteger')
            ->addColumn('currency', 'string', ['limit' => 3])
            ->addColumn('color', 'string', ['limit' => 7])
            ->addColumn('vat_id', 'string')
            ->addColumn('time_budget', 'biginteger')
            ->create();
    }
}
