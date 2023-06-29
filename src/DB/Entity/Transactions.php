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
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

#[Entity]
class Transactions
{
    #[Id]
    #[Column(unique: true, insertable: true, options: ['unsigned' => true])]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;
    #[Column(length: 50)]
    private string $type;
    #[Column]
    private \DateTime $paidAt;
    #[Column]
    private string $transactionOfMonth;
    #[Column]
    private float $amount;
    #[Column(length: 14)]
    private string $currencyCode;
    #[Column]
    private string $reference;
    #[Column(nullable: true, type: 'bigint', options: ['unsigned' => true])]
    private ?string $nfse;
    #[Column]
    private string $taxNumber;
    #[Column]
    private string $customerReference;
    #[Column(type: 'bigint')]
    private int $contactId;
    #[Column]
    private string $contactReference;
    #[Column]
    private string $contactName;
    #[Column]
    private string $contactType;
    #[Column(type: 'bigint')]
    private int $categoryId;
    #[Column]
    private string $categoryName;
    #[Column]
    private string $categoryType;
    #[Column]
    private array $metadata;
}
