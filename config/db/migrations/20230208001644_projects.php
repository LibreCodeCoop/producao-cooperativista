<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Projects extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('projects');
        $users
            ->addColumn('parent_title', 'string', ['limit' => 150])
            ->addColumn('customer_id', 'biginteger')
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('start', 'date')
            ->addColumn('end', 'date')
            ->addColumn('comment', 'text')
            ->addColumn('visible', 'smallinteger')
            ->addColumn('billable', 'smallinteger')
            ->addColumn('color', 'string', ['limit' => 7])
            ->addColumn('global_activities', 'smallinteger')
            ->create();
    }
}
