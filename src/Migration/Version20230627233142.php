<?php

/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace ProducaoCooperativista\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Timesheet';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('timesheet');
        $table->addColumn('id', 'bigint', ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('activity_id', 'bigint', ['unsigned' => true]);
        $table->addColumn('project_id', 'bigint');
        $table->addColumn('user_id', 'bigint');
        $table->addColumn('begin', 'datetime');
        $table->addColumn('end', 'datetime');
        $table->addColumn('duration', 'bigint', ['notnull' => false]);
        $table->addColumn('description', 'text', ['notnull' => false]);
        $table->addColumn('rate', 'float');
        $table->addColumn('internal_rate', 'float');
        $table->addColumn('exported', 'smallint');
        $table->addColumn('billable', 'smallint');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('timesheet');
    }
}
