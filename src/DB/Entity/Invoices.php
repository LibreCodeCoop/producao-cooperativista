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

namespace ProducaoCooperativista\DB\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use ProducaoCooperativista\DB\Entity as DBEntity;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;

#[Entity]
class Invoices extends DBEntity
{
    use MagicGetterSetterTrait;
    #[Id]
    #[Column(insertable: true, options: ['unsigned' => true])]
    private int $id;
    #[Column(length: 50)]
    private string $type;
    #[Column]
    private \DateTime $issuedAt;
    #[Column]
    private \DateTime $dueAt;
    #[Column]
    private string $transactionOfMonth;
    #[Column]
    private float $amount;
    #[Column(nullable: true)]
    private ?float $discountPercentage;
    #[Column(length: 14)]
    private string $currencyCode;
    #[Column]
    private string $documentNumber;
    #[Column(nullable: true, type: 'bigint', options: ['unsigned' => true])]
    private ?int $nfse;
    #[Column(nullable: true)]
    private ?string $taxNumber;
    #[Column(nullable: true)]
    private ?string $customerReference;
    #[Column(type: 'bigint')]
    private int $contactId;
    #[Column(length: 191, nullable: true)]
    private ?string $contactReference;
    #[Column]
    private string $contactName;
    #[Column(length: 191, nullable: true)]
    private ?string $contactType;
    #[Column(type: 'bigint')]
    private int $categoryId;
    #[Column]
    private string $categoryName;
    #[Column]
    private string $categoryType;
    #[Column(type:'smallint', options: ['default' => 0])]
    private int $archive = 0;
    #[Column]
    private array $metadata;
}
