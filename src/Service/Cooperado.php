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

namespace App\Service;

use NumberFormatter;
use App\Helper\Dates;
use App\Helper\MagicGetterSetterTrait;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\FRRA;
use App\Service\Akaunting\Document\ProducaoCooperativista;
use App\Service\Akaunting\Document\Taxes\InssIrpf;
use App\Service\Akaunting\Source\Documents;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @method self setAkauntingContactId(int $value)
 * @method int getAkauntingContactId()
 * @method self setDependentes(int $value)
 * @method int getDependentes()
 * @method self setFrra(FRRA $value)
 * @method self setProducaoCooperativista(ProducaoCooperativista $value)
 * @method self setName(string $value)
 * @method string getName()
 * @method self setInssIrpf(InssIrpf $value)
 * @method self setTaxNumber(string $value)
 * @method string getTaxNumber()
 * @method self setWeight(float $value)
 * @method float getWeight()
 * @method self setPesoFinal()
 * @method float getPesoFinal()
 * @method self setTrabalhado(int $value)
 * @method int getTrabalhado()
 */
class Cooperado
{
    use MagicGetterSetterTrait;

    private ?int $akauntingContactId = 0;
    private ?int $dependentes = 0;
    private ?string $name = '';
    private ?string $taxNumber = '';
    private ?float $weight = 1;
    private ?float $pesoFinal = 0;
    private ?int $trabalhado = 0;
    private ?ProducaoCooperativista $producaoCooperativista = null;
    private ?FRRA $frra = null;
    private ?InssIrpf $inssIrpf = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Dates $dates,
        private NumberFormatter $numberFormatter,
        private Documents $documents,
        private Request $request,
        private ?int $anoFiscal = null,
        private ?int $mes = null,
    ) {
    }

    public function getProducaoCooperativista(): ProducaoCooperativista
    {
        if ($this->producaoCooperativista) {
            return $this->producaoCooperativista;
        }
        $this->producaoCooperativista = new ProducaoCooperativista(
            anoFiscal: $this->anoFiscal,
            mes: $this->mes,
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        );
        return $this->producaoCooperativista;
    }

    public function getFrra(): FRRA
    {
        if ($this->frra) {
            return $this->frra;
        }
        $this->frra = new FRRA(
            anoFiscal: $this->anoFiscal,
            mes: $this->mes,
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        );
        return $this->frra;
    }

    public function getInssIrpf(): InssIrpf
    {
        if ($this->inssIrpf) {
            return $this->inssIrpf;
        }
        $this->inssIrpf = new InssIrpf(
            anoFiscal: $this->anoFiscal,
            mes: $this->mes,
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        );
        return $this->inssIrpf;
    }
}
