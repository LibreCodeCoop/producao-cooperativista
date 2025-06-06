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

namespace App\Entity\Akaunting;

use App\Helper\MagicGetterSetterTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contacts')]
class Contacts
{
    use MagicGetterSetterTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
    #[ORM\Column(name: 'company_id')]
    private ?int $companyId;
    #[ORM\Column]
    private string $type;
    #[ORM\Column]
    private string $name;
    #[ORM\Column(name: 'tax_number', nullable: true, length: 20)]
    private ?string $taxNumber;
    #[ORM\Column]
    private string $country;
    #[ORM\Column(name: 'currency_code')]
    private string $currencyCode;
    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $enabled;
    #[ORM\Column(name: 'created_at')]
    private \DateTime $createdAt;
    #[ORM\Column(name: 'updated_at')]
    private \DateTime $updatedAt;
}
