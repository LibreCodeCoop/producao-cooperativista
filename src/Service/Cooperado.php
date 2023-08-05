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

namespace ProducaoCooperativista\Service;

use NumberFormatter;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\Dates;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Provider\Akaunting\Request;
use ProducaoCooperativista\Service\Akaunting\Document\FRRA;
use ProducaoCooperativista\Service\Akaunting\Document\ProducaoCooperativista;
use ProducaoCooperativista\Service\Akaunting\Document\Taxes\InssIrpf;
use ProducaoCooperativista\Service\Akaunting\Source\Documents;

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
 */
class Cooperado
{
    use MagicGetterSetterTrait;

    private ?int $akauntingContactId = 0;
    private ?int $dependentes = 0;
    private ?string $name = '';
    private ?string $taxNumber = '';
    private ?ProducaoCooperativista $producaoCooperativista = null;
    private ?FRRA $frra = null;
    private ?InssIrpf $inssIrpf = null;

    public function __construct(
        private ?int $anoFiscal,
        private Database $db,
        private Dates $dates,
        private NumberFormatter $numberFormatter,
        private Documents $documents,
        private Request $request,
    ) {
    }

    public function getProducaoCooperativista(): ProducaoCooperativista
    {
        if ($this->producaoCooperativista) {
            return $this->producaoCooperativista;
        }
        $this->setProducaoCooperativista(new ProducaoCooperativista(
            anoFiscal: $this->anoFiscal,
            db: $this->db,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        ));
        return $this->producaoCooperativista;
    }

    public function getFrra(): FRRA
    {
        if ($this->frra) {
            return $this->frra;
        }
        $this->setFrra(new FRRA(
            anoFiscal: $this->anoFiscal,
            db: $this->db,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        ));
        return $this->frra;
    }

    public function getInssIrpf(): InssIrpf
    {
        if ($this->inssIrpf) {
            return $this->inssIrpf;
        }
        $this->setInssIrpf(new InssIrpf(
            anoFiscal: $this->anoFiscal,
            db: $this->db,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            cooperado: $this,
            request: $this->request,
        ));
        return $this->inssIrpf;
    }
}
