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
            ->addColumn('title', 'string', ['limit' => 50])
            ->addColumn('username', 'string', ['limit' => 180])
            ->addColumn('enabled', 'smallinteger')
            ->addColumn('color', 'string', ['limit' => 7])
            ->create();
    }
}
