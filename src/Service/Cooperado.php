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
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Service\AkauntingDocument\FRRA;
use ProducaoCooperativista\Service\AkauntingDocument\ProducaoCooperativista;
use ProducaoCooperativista\Service\Source\Invoices;

/**
 * @method self setAkauntingContactId(int $value)
 * @method int getAkauntingContactId()
 * @method self setDependentes(int $value)
 * @method int getDependentes()
 * @method self setFrra(int $value)
 * @method FRRA getFrra()
 * @method self setProducao(Producao $value)
 * @method Producao getProducao()
 * @method self setProducaoCooperativista(ProducaoCooperativista $value)
 * @method ProducaoCooperativista getProducaoCooperativista()
 * @method self setName(string $value)
 * @method string getName()
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
    private Producao $producao;
    private ProducaoCooperativista $producaoCooperativista;
    private FRRA $frra;

    public function __construct(
        private ?int $anoFiscal,
        private Database $db,
        private Dates $dates,
        private NumberFormatter $numberFormatter,
        private Invoices $invoices
    )
    {
        $this->setProducao(new Producao(
            anoFiscal: $anoFiscal,
            cooperado: $this
        ));
        $this->setProducaoCooperativista(new ProducaoCooperativista(
            anoFiscal: $anoFiscal,
            db: $this->db,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            invoices: $this->invoices,
            cooperado: $this
        ));
        $this->getProducaoCooperativista()->setProducao($this->getProducao());
        $this->setFrra(new FRRA(
            anoFiscal: $anoFiscal,
            db: $this->db,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            invoices: $this->invoices,
            cooperado: $this
        ));
        $this->getFrra()->setProducao(new Producao(
            anoFiscal: $anoFiscal,
            cooperado: $this
        ));
    }
}