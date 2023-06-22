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

use ProducaoCooperativista\Helper\MagicGetterSetterTrait;

/**
 * @method CooperadoProducao setAkauntingContactId(int $value)
 * @method int getAkauntingContactId()
 * @method CooperadoProducao setAuxilio(float $value)
 * @method float getAuxilio()
 * @method CooperadoProducao setBaseIrpf(float $value)
 * @method float getBaseIrpf()
 * @method CooperadoProducao setBaseProducao(float $value)
 * @method float getBaseProducao()
 * @method CooperadoProducao setBillId(int $value)
 * @method int getBillId()
 * @method CooperadoProducao setBruto(float $value)
 * @method float getBruto()
 * @method CooperadoProducao setDependentes(int $value)
 * @method int getDependentes()
 * @method CooperadoProducao setDocumentNumber(string $value)
 * @method string getDocumentNumber()
 * @method CooperadoProducao setFrra(float $value)
 * @method float getFrra()
 * @method CooperadoProducao setHealthInsurance(float $value)
 * @method float getHealthInsurance()
 * @method CooperadoProducao setInss(float $value)
 * @method float getInss()
 * @method CooperadoProducao setIrpf(float $value)
 * @method float getIrpf()
 * @method CooperadoProducao setLiquido(float $value)
 * @method float getLiquido()
 * @method CooperadoProducao setName(string $value)
 * @method string getName()
 * @method CooperadoProducao setTaxNumber(string $value)
 * @method string getTaxNumber()
 */
class CooperadoProducao
{
    use MagicGetterSetterTrait;
    private ?int $akauntingContactId;
    private ?int $anoFiscal;
    private ?float $auxilio;
    private ?float $baseIrpf;
    private ?float $baseProducao;
    private ?int $billId;
    private ?float $bruto;
    private ?int $dependentes;
    private ?string $documentNumber;
    private ?float $frra;
    private ?float $healthInsurance;
    private ?float $inss;
    private ?float $irpf;
    private ?float $liquido;
    private ?string $name;
    private ?string $taxNumber;
    private const STATUS_NEED_TO_UPDATE = 0;
    private const STATUS_UPDATING = 1;
    private const STATUS_UPDATED = 2;
    private int $updated = self::STATUS_UPDATED;

    public function __construct(int $anoFiscal)
    {
        $this->anoFiscal = $anoFiscal;
    }

    public function __call($name, $arguments) {
        if (!preg_match('/^(?<type>get|set)(?<property>.+)/', $name, $matches)) {
            throw new \LogicException(sprintf('Cannot set non existing property %s->%s = %s.', \get_class($this), $name, var_export($arguments, true)));
        }
        $property = lcfirst($matches['property']);
        if (!property_exists($this, $property)) {
            throw new \LogicException(sprintf('Cannot set non existing property %s->%s = %s.', \get_class($this), $name, var_export($arguments, true)));
        }
        if ($matches['type'] === 'get') {
            if ($this->updated === self::STATUS_NEED_TO_UPDATE) {
                $this->calculaLiquido();
            }
            return $this->$property;
        }
        if ($this->updated !== self::STATUS_UPDATING) {
            $this->updated = self::STATUS_NEED_TO_UPDATE;
        }
        $this->$property = $arguments[0] ?? null;
        return $this;
    }

    private function calculaLiquido(): void
    {
        $this->updated = self::STATUS_UPDATING;

        $inss = new INSS();
        $irpf = new IRPF($this->anoFiscal);

        $this->setFrra($this->getBaseProducao() * (1 / 12));
        $this->setAuxilio($this->getBaseProducao() * 0.2);
        $this->setBruto($this->getBaseProducao() - $this->getAuxilio() - $this->getFrra());
        $this->setInss($inss->calcula($this->getBruto()));
        $this->setBaseIrpf($irpf->calculaBase(
            $this->getBruto(),
            $this->getInss(),
            $this->getDependentes()
        ));
        $this->setIrpf($irpf->calcula($this->getBaseIrpf(), $this->getDependentes()));
        $this->setLiquido(
            $this->getBruto()
            - $this->getInss()
            - $this->getIrpf()
            - $this->getHealthInsurance()
            + $this->getAuxilio()
        );
        $this->updated = self::STATUS_UPDATED;
    }

    public function toArray(): array
    {
        return [
            'akaunting_contact_id' => $this->getAkauntingContactId(),
            'auxilio' => $this->getAuxilio(),
            'base_irpf' => $this->getBaseIrpf(),
            'base_producao' => $this->getBaseProducao(),
            'bruto' => $this->getBruto(),
            'dependentes' => $this->getDependentes(),
            'document_number' => $this->getDocumentNumber(),
            'frra' => $this->getFrra(),
            'health_insurance' => $this->getHealthInsurance(),
            'inss' => $this->getInss(),
            'irpf' => $this->getIrpf(),
            'liquido' => $this->getLiquido(),
            'name' => $this->getName(),
            'tax_number' => $this->getTaxNumber(),
        ];
    }
}
