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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Nfse
{
    use EntityArrayMapperTrait;
    private int $id;
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private int $numero;
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $numeroSubstituta;
    #[ORM\Column(length: 14)]
    private string $cnpj;
    #[ORM\Column]
    private string $razaoSocial;
    #[ORM\Column]
    private \DateTime $dataEmissao;
    #[ORM\Column]
    private float $valorServico;
    #[ORM\Column]
    private float $valorCofins;
    #[ORM\Column]
    private float $valorIr;
    #[ORM\Column]
    private float $valorPis;
    #[ORM\Column]
    private float $valorIss;
    #[ORM\Column(type: Types::TEXT)]
    private string $discriminacaoNormalizada;
    #[ORM\Column(nullable: true)]
    private ?string $setor;
    #[ORM\Column]
    private string $codigoCliente;
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;
}
