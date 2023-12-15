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

use UnexpectedValueException;

class FRRA extends ProducaoCooperativista
{
    protected string $whoami = 'PDC';

    protected function setUp(): self
    {
        $this->getValues()->setLockFrra(true);
        try {
            $this->getDueAt();
        } catch (UnexpectedValueException $e) {
            $this->changeDueAt($this->dates->getPrevisaoPagamentoFrra());
        }
        return parent::setUp();
    }

    protected function getDocumentNumber(): string
    {
        if (empty($this->documentNumber)) {
            $cooperado = $this->getCooperado();
            $this->setDocumentNumber(
                $this->whoami . '_' .
                $cooperado->getTaxNumber() .
                '-' .
                $this->dates->getPrevisaoPagamentoFrra()
                    ->modify('-2 month')
                    ->format('Y-m')
            );
        }
        return $this->documentNumber;
    }

    public function save(): self
    {
        $this->coletaInvoiceNaoPago();
        if ($this->getId()) {
            $this->update();
            return $this;
        }
        $this->insert();
        return $this;
    }

    private function update(): self
    {
        $description = sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m'));

        $values = $this->getValues();
        $currentFrra = $values->getFrra();

        $this
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: $description,
                price: $currentFrra
            );

        parent::save();
        return $this;
    }

    private function insert(): self
    {
        $cooperado = $this->getCooperado();
        $values = $this->getValues();
        $this
            ->setType('bill')
            ->setCategoryId((int) getenv('AKAUNTING_FRRA_CATEGORY_ID'))
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setNote('Dia útil padrão de pagamento', sprintf('%sº', $this->dates->getPagamentoNoDiaUtil()))
            ->setNote('Previsão de pagamento no dia', $this->dates->getPrevisaoPagamentoFrra()->format('Y-m-d'))
            ->setNote('Base de cálculo', $this->numberFormatter->format($values->getBaseProducao()))
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber())
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                price: $values->getFrra()
            )
            ->setTaxes();
        parent::save();
        return $this;
    }
}
