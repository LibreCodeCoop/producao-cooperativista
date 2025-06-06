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

/**
 * $method int getId()
 * $method string getName()
 * $method string getType()
 * $method int getEnabled()
 * $method ?int getParentId()
 * $method array getMetadata()
 */
#[ORM\Table(name: 'categories')]
#[ORM\Entity]
class Category
{
    use MagicGetterSetterTrait;
    use EntityArrayMapperTrait;
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT, insertable: true, options: ['unsigned' => true])]
    private int $id;
    #[ORM\Column]
    private string $name;
    #[ORM\Column(length: 50)]
    private string $type;
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $enabled;
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $parentId;
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;
}
