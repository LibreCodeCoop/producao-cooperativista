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

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Transactions';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('transactions');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('type', 'string', ['length' => 50]);
        $table->addColumn('paid_at', 'datetime');
        $table->addColumn('transaction_of_month', 'string');
        $table->addColumn('amount', 'float');
        $table->addColumn('currency_code', 'string', ['length' => 14]);
        $table->addColumn('reference', 'string', ['notnull' => false]);
        $table->addColumn('nfse', 'bigint', ['unsigned' => true, 'notnull' => false]);
        $table->addColumn('tax_number', 'string', ['notnull' => false]);
        $table->addColumn('customer_reference', 'string', ['notnull' => false]);
        $table->addColumn('contact_id', 'bigint');
        $table->addColumn('contact_reference', 'string', ['notnull' => false]);
        $table->addColumn('contact_name', 'string');
        $table->addColumn('contact_type', 'string');
        $table->addColumn('category_id', 'bigint');
        $table->addColumn('category_name', 'string');
        $table->addColumn('category_type', 'string');
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('transactions');
    }
}
