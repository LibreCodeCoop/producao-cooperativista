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

final class Version20230627233140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Users';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('users');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('alias', 'string', ['length' => 60]);
        $table->addColumn('kimai_username', 'string', ['length' => 180]);
        $table->addColumn('akaunting_contact_id', 'bigint', ['notnull' => false]);
        $table->addColumn('tax_number', 'string', ['length' => 20, 'notnull' => false]);
        $table->addColumn('dependents', 'smallint');
        $table->addColumn('enabled', 'smallint');
        $table->addColumn('peso', 'float', ['notnull' => false]);
        $table->addColumn('metadata', 'json');
        $table->addUniqueConstraint(['kimai_username']);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}
