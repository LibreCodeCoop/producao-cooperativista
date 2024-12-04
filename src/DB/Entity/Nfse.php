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

#[Entity]
class Nfse
{
    private int $id;
    #[Id]
    #[Column(type: 'bigint', options: ['unsigned' => true])]
    private int $numero;
    #[Column(type: 'bigint', nullable: true)]
    private ?int $numeroSubstituta;
    #[Column(length: 14)]
    private string $cnpj;
    #[Column]
    private string $razaoSocial;
    #[Column]
    private \DateTime $dataEmissao;
    #[Column]
    private float $valorServico;
    #[Column]
    private float $valorCofins;
    #[Column]
    private float $valorIr;
    #[Column]
    private float $valorPis;
    #[Column]
    private float $valorIss;
    #[Column(type: 'text')]
    private string $discriminacaoNormalizada;
    #[Column(nullable: true)]
    private ?string $setor;
    #[Column]
    private string $codigoCliente;
    #[Column]
    private array $metadata;
}
