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

namespace ProducaoCooperativista\DB\Entity;

class Transactions
{
    private int $id;
    private ?string $type;
    private ?\DateTime $paidAt;
    private ?string $transactionOfMonth;
    private ?float $amount;
    private ?string $currencyCode;
    private ?string $reference;
    private ?string $nfse;
    private ?string $taxNumber;
    private ?string $customerReference;
    private ?int $contactId;
    private ?string $contactReference;
    private ?string $contactName;
    private ?string $contactType;
    private ?int $categoryId;
    private ?string $categoryName;
    private ?string $categoryType;
    private ?array $metadata;
}