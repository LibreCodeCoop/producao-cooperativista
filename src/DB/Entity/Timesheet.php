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

#[Entity()]
class Timesheet
{
    #[Id]
    #[Column(insertable: true, type: 'bigint', options: ['unsigned' => true])]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;
    #[Column(type: 'bigint', options: ['unsigned' => true])]
    private int $activityId;
    #[Column(type: 'bigint')]
    private int $projectId;
    #[Column(type: 'bigint')]
    private int $userId;
    #[Column]
    private \DateTime $begin;
    #[Column]
    private \DateTime $end;
    #[Column(type: 'bigint', nullable: true)]
    private ?int $duration;
    #[Column(type: 'text', nullable: true)]
    private ?string $description;
    #[Column]
    private float $rate;
    #[Column]
    private float $internalRate;
    #[Column(type: 'smallint')]
    private int $exported;
    #[Column(type: 'smallint')]
    private int $billable;
}
