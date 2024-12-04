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
use ProducaoCooperativista\DB\Entity as DBEntity;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;

#[Entity]
class Customers extends DBEntity
{
    use MagicGetterSetterTrait;
    #[Id]
    #[Column(insertable: true, options: ['unsigned' => true])]
    private int $id;
    #[Column(length: 150)]
    private string $name;
    #[Column(length: 50, nullable: true)]
    private ?string $number;
    #[Column(type: 'text', nullable: true)]
    private ?string $comment;
    #[Column(type: 'smallint')]
    private int $visible;
    #[Column(type: 'smallint')]
    private int $billable;
    #[Column(length: 3)]
    private string $currency;
    #[Column(length: 7, nullable: true)]
    private ?string $color;
    #[Column(unique: true, nullable: true)]
    private ?string $vatId;
    #[Column(type: 'bigint')]
    private ?int $timeBudget;
}
