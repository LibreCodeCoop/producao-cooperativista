<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Timesheet extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('timesheet');
        $users
            ->addColumn('activity_id', 'biginteger')
            ->addColumn('project_id', 'biginteger')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('begin', 'datetime')
            ->addColumn('end', 'datetime')
            ->addColumn('duration', 'biginteger')
            ->addColumn('description', 'text')
            ->addColumn('rate', 'double')
            ->addColumn('internalRate', 'double')
            ->addColumn('exported', 'smallinteger')
            ->addColumn('billable', 'smallinteger')
            ->create();
    }
}
