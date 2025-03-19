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

final class Version20230627233141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Customers';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('customers');
        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('name', Types::STRING, ['length' => 150]);
        $table->addColumn('number', Types::STRING, ['length' => 50, 'notnull' => false]);
        $table->addColumn('comment', Types::TEXT, ['notnull' => false]);
        $table->addColumn('visible', Types::BOOLEAN);
        $table->addColumn('billable', Types::BOOLEAN);
        $table->addColumn('currency', Types::STRING, ['length' => 3]);
        $table->addColumn('color', Types::STRING, ['length' => 7, 'notnull' => false]);
        $table->addColumn('vat_id', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('time_budget', Types::BIGINT);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['vat_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('customers');
    }
}
