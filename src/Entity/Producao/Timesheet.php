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

namespace App\Entity\Producao;

use App\Helper\EntityArrayMapperTrait;
use App\Helper\MagicGetterSetterTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "timesheet")]
class Timesheet
{
    use MagicGetterSetterTrait;
    use EntityArrayMapperTrait;
    #[ORM\Id]
    #[ORM\Column(insertable: true, type: Types::BIGINT, options: ['unsigned' => true])]
    private int $id;
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private int $activityId;
    #[ORM\Column(type: Types::BIGINT)]
    private int $projectId;
    #[ORM\Column(type: Types::BIGINT)]
    private int $userId;
    #[ORM\Column]
    private \DateTime $begin;
    #[ORM\Column]
    private \DateTime $end;
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $duration;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;
    #[ORM\Column]
    private float $rate;
    #[ORM\Column]
    private float $internalRate;
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $exported;
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $billable;
}
