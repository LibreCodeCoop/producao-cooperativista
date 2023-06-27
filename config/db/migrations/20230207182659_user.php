<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class User extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('users');
        $users
            ->addColumn('alias', 'string', ['limit' => 60])
            ->addColumn('kimai_username', 'string', ['limit' => 180])
            ->addColumn('akaunting_contact_id', 'biginteger', ['null' => true])
            ->addColumn('tax_number', 'string', ['limit' => 20])
            ->addColumn('dependents', 'smallinteger')
            ->addColumn('health_insurance', 'double')
            ->addColumn('enabled', 'smallinteger')
            ->addColumn('metadata', 'json')
            ->create();
    }
}
