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

namespace ProducaoCooperativista\Service\AkauntingDocument;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

class FRRA extends AAkauntingDocument
{
    private function coletaNaoPago(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_FRRA_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($this->getCooperadoProducao()->getTaxNumber(), ParameterType::INTEGER)));

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
        $this->coletaNaoPago();
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
        // Sum all items that isn't taxes
        $total = array_reduce($this->items, function (float $total, array $item)  {
            $taxesIds = [
                $this->itemsIds['INSS'],
                $this->itemsIds['IRRF'],
            ];
            if (!in_array($item['item_id'], $taxesIds)) {
                $total += $item['price'];
            }
            return $total;
        }, 0);

        $cooperado = $this->getCooperadoProducao();
        // Backup of current FRRA to don't lost this value after update "baseProducao"
        $currentFrra = $cooperado->getFrra();

        // Update the "baseProducao" with total of items that isn't tax
        $cooperado->setIsFrra(true);
        $cooperado->setBaseProducao($total);

        $this
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                price: $currentFrra
            )
            ->setTaxes()
            ->setNote('Base de cálculo', $this->numberFormatter->format($cooperado->getBaseProducao()));
        parent::save();
        return $this;
    }

    private function insert(): self
    {
        $cooperado = $this->getCooperadoProducao();
        $cooperado->setIsFrra(true);
        $cooperado->setBaseProducao($cooperado->getFrra());
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
            ->setNote('Base de cálculo', $this->numberFormatter->format($cooperado->getBaseProducao()))
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber())
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                price: $cooperado->getBaseProducao()
            )
            ->setTaxes();
        parent::save();
        return $this;
    }
}
