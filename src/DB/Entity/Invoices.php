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

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use ProducaoCooperativista\DB\Entity as DBEntity;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;

#[Entity]
#[Table(name: 'invoices')]
class Invoices extends DBEntity
{
    use MagicGetterSetterTrait;
    private int $id;
    private int $akauntingId;
    private ?string $type;
    private ?\DateTime $issuedAt;
    private ?\DateTime $dueAt;
    private ?string $transactionOfMonth;
    private ?float $amount;
    private ?string $currencyCode;
    private ?string $documentNumber;
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
