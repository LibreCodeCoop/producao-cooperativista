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

final class Version20230627233146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Invoices';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('invoices');
        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('type', Types::STRING, ['length' => 50]);
        $table->addColumn('issued_at', Types::DATETIME_MUTABLE);
        $table->addColumn('due_at', Types::DATETIME_MUTABLE);
        $table->addColumn('transaction_of_month', Types::STRING, ['length' => 100]);
        $table->addColumn('amount', Types::FLOAT);
        $table->addColumn('discount_percentage', Types::FLOAT, ['notnull' => false]);
        $table->addColumn('currency_code', Types::STRING, ['length' => 14]);
        $table->addColumn('document_number', Types::STRING, ['length' => 255]);
        $table->addColumn('nfse', Types::BIGINT, ['unsigned' => true, 'notnull' => false]);
        $table->addColumn('tax_number', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('customer_reference', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('contact_id', Types::BIGINT);
        $table->addColumn('contact_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('contact_name', Types::STRING, ['length' => 255]);
        $table->addColumn('contact_type', Types::STRING, ['notnull' => false, 'length' => 191]);
        $table->addColumn('category_id', Types::BIGINT);
        $table->addColumn('category_name', Types::STRING, ['length' => 255]);
        $table->addColumn('category_type', Types::STRING, ['length' => 255]);
        $table->addColumn('archive', Types::SMALLINT, ['default' => 0]);
        $table->addColumn('metadata', Types::JSON);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoices');
    }
}
