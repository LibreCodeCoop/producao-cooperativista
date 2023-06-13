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

use Phinx\Migration\AbstractMigration;

final class Invoices extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('invoices');
        $users
            ->addColumn('type', 'string', ['limit' => 50])
            ->addColumn('issued_at', 'date')
            ->addColumn('due_at', 'date')
            ->addColumn('transaction_of_month', 'string')
            ->addColumn('amount', 'double')
            ->addColumn('currency_code', 'string', ['limit' => 14])
            ->addColumn('document_number', 'string')
            ->addColumn('nfse', 'text')
            ->addColumn('tax_number', 'string')
            ->addColumn('customer_reference', 'string')
            ->addColumn('contact_id', 'biginteger')
            ->addColumn('contact_reference', 'string')
            ->addColumn('contact_name', 'string')
            ->addColumn('contact_type', 'string')
            ->addColumn('category_id', 'biginteger')
            ->addColumn('category_name', 'string')
            ->addColumn('category_type', 'string')
            ->addColumn('metadata', 'text')
            ->create();
    }
}
