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

namespace ProducaoCooperativista\Service\Akaunting\Document;

use ProducaoCooperativista\Service\Cooperado;
use ProducaoCooperativista\Service\INSS;
use ProducaoCooperativista\Service\IRPF;

/**
 * @method self setAuxilio(float $value)
 * @method float getAuxilio()
 * @method self setBaseIrpf(float $value)
 * @method float getBaseIrpf()
 * @method self setBaseProducao(float $value)
 * @method float getBaseProducao()
 * @method self setBruto(float $value)
 * @method float getBruto()
 * @method self setCooperado(Cooperado $value)
 * @method Cooperado getCooperado()
 * @method self setDocumentNumber(string $value)
 * @method string getDocumentNumber()
 * @method self setFrra(float $value)
 * @method float getFrra()
 * @method self setFrraDocumentNumber(float $value)
 * @method float getFrraDocumentNumber()
 * @method self setHealthInsurance(float $value)
 * @method float getHealthInsurance()
 * @method self setInss(float $value)
 * @method float getInss()
 * @method self setIrpf(float $value)
 * @method float getIrpf()
 * @method self setIsFrra(bool $value)
 * @method bool getIsFrra()
 * @method self setLiquido(float $value)
 * @method float getLiquido()
 * @method self setAdiantamento()
 * @method array getAdiantamento()
 */
class Values
{
    private ?float $auxilio = 0;
    private ?float $baseIrpf = 0;
    private ?float $baseProducao = 0;
    private ?float $bruto = 0;
    private ?string $documentNumber = '';
    private ?float $frra = 0;
    private ?string $frraDocumentNumber = '';
    private ?float $inss = 0;
    private ?float $irpf = 0;
    private ?float $liquido = 0;
    private ?float $healthInsurance = 0;
    private ?array $adiantamento = [];
    private bool $isFrra = false;
    private const STATUS_NEED_TO_UPDATE = 0;
    private const STATUS_UPDATING = 1;
    private const STATUS_UPDATED = 2;
    private int $updated = self::STATUS_UPDATED;


    public function __construct(
        private ?int $anoFiscal,
        private ?Cooperado $cooperado
    ) {
    }

    public function __call($name, $arguments)
    {
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
        if (isset($this->$property) && $this->$property === $arguments[0]) {
            return $this;
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

        if (!$this->isFrra) {
            $this->setFrra($this->getBaseProducao() * (1 / 12));
        }
        $this->setAuxilio($this->getBaseProducao() * 0.2);
        $this->setBruto($this->getBaseProducao() - $this->getAuxilio() - $this->getFrra());
        $liquido = $this->getBruto()
            - $this->getInss()
            - $this->getIrpf()
            - $this->getTotalAdiantamento()
            + $this->getAuxilio();
        if (!$this->isFrra) {
            $liquido -= $this->getHealthInsurance();
        }
        $this->setLiquido($liquido);
        $this->updated = self::STATUS_UPDATED;
    }

    private function getTotalAdiantamento(): float
    {
        $total = 0;
        foreach ($this->getAdiantamento() as $linha) {
            $total+= $linha['amount'];
        }
        return $total;
    }

    /**
     * When change the base all values will be set to zero
     */
    public function setBaseProducao(float $baseProducao): self
    {
        if ($baseProducao !== $this->baseProducao) {
            $this->updated = self::STATUS_NEED_TO_UPDATE;
        }
        $this->baseProducao = $baseProducao;

        $this->auxilio = 0;
        $this->baseIrpf = 0;
        $this->bruto = 0;
        $this->frra = 0;
        $this->inss = 0;
        $this->irpf = 0;
        $this->liquido = 0;

        $this->calculaImpostos();
        return $this;
    }

    public function calculaImpostos(): void
    {
        $inss = new INSS();
        $irpf = new IRPF($this->anoFiscal);

        $this->setInss($inss->calcula($this->getBruto()));
        $this->setBaseIrpf($irpf->calculaBase(
            $this->getBruto(),
            $this->getInss(),
            $this->getCooperado()->getDependentes()
        ));
        $this->setIrpf($irpf->calcula($this->getBaseIrpf(), $this->getCooperado()->getDependentes()));
    }

    public function toArray(): array
    {
        $cooperado = $this->getCooperado();
        return [
            'akaunting_contact_id' => $cooperado->getAkauntingContactId(),
            'auxilio' => $this->getAuxilio(),
            'base_irpf' => $this->getBaseIrpf(),
            'base_producao' => $this->getBaseProducao(),
            'bruto' => $this->getBruto(),
            'dependentes' => $cooperado->getDependentes(),
            'document_number' => $this->getDocumentNumber(),
            'frra' => $this->getFrra(),
            'health_insurance' => $this->getHealthInsurance(),
            'inss' => $this->getInss(),
            'irpf' => $this->getIrpf(),
            'adiantamentos' => array_map(fn($i) => ['valor' => $i['amount']], $this->getAdiantamento()),
            'liquido' => $this->getLiquido(),
            'name' => $cooperado->getName(),
            'tax_number' => $cooperado->getTaxNumber(),
        ];
    }
}
