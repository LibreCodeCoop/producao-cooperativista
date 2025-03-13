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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
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
        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('alias', Types::STRING, ['length' => 60]);
        $table->addColumn('kimai_username', Types::STRING, ['length' => 180]);
        $table->addColumn('akaunting_contact_id', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('tax_number', Types::STRING, ['length' => 20, 'notnull' => false]);
        $table->addColumn('dependents', Types::SMALLINT);
        $table->addColumn('enabled', Types::SMALLINT);
        $table->addColumn('peso', Types::FLOAT, ['notnull' => false]);
        $table->addColumn('metadata', Types::JSON);
        $table->addUniqueConstraint(['kimai_username']);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}
