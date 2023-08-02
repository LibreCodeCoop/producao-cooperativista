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

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

class FRRA extends AAkauntingDocument
{
    protected function setUp(): self
    {
        $this->getValues()->setIsFrra(true);
        return $this;
    }

    private function coletaInvoiceNaoPago(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_FRRA_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($this->getCooperado()->getTaxNumber(), ParameterType::INTEGER)));

        $result = $select->executeQuery();
        $row = $result->fetchAssociative();
        if (!$row) {
            return $this;
        }
        $this->setId($row['id'])
            ->loadFromAkaunting($row['id']);
        return $this;
    }

    public function save(): self
    {
        $this->coletaInvoiceNaoPago();
        if ($this->getId()) {
            $this->setSearch('type:bill');
            $this->update();
            return $this;
        }
        $this->insert();
        return $this;
    }

    private function update(): self
    {
        $description = sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m'));
        // Get current FRRA item
        $current = array_filter($this->items, function ($item) use ($description) {
            if ($item['description'] === $description) {
                if ($item['item_id'] === $this->itemsIds['frra']) {
                    return true;
                }
            }
            return false;
        });
        $current = current($current);
        // Sum all items that isn't taxes and isn't current FRRA because the current FRRA could be a different value
        $total = array_reduce($this->items, function (float $total, array $item) use ($current) {
            $taxesIds = [
                $this->itemsIds['INSS'],
                $this->itemsIds['IRRF'],
            ];
            if (!in_array($item['item_id'], $taxesIds)) {
                if ($current && $item['item_id'] === $current['item_id']) {
                    if (empty($current['description']) || $item['description'] === $current['description']) {
                        return $total;
                    }
                }
                $total += $item['price'];
            }
            return $total;
        }, 0);

        $values = $this->getValues();
        // Backup of current FRRA to don't lost this value after update "baseProducao"
        $currentFrra = $values->getBaseProducao();

        // Update the "baseProducao" with total of items that isn't tax
        $values->setBaseProducao($total + $currentFrra);

        $this
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: $description,
                price: $values->getBaseProducao()
            )
            ->setTaxes()
            ->setNote('Base de cálculo', $this->numberFormatter->format($values->getBaseProducao()));
        parent::save();
        return $this;
    }

    private function insert(): self
    {
        $cooperado = $this->getCooperado();
        $values = $this->getValues();
        $this
            ->setType('bill')
            ->setCategoryId((int) $_ENV['AKAUNTING_FRRA_CATEGORY_ID'])
            ->setDocumentNumber(
                'FRRA_' .
                $cooperado->getTaxNumber() .
                '-' .
                $this->dates->getPrevisaoPagamentoFrra()->format('Y-m')
            )
            ->setSearch('type:bill')
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setDueAt($this->dates->getPrevisaoPagamentoFrra()->format('Y-m-d H:i:s'))
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
                price: $values->getBaseProducao()
            )
            ->setTaxes();
        parent::save();
        return $this;
    }
}
