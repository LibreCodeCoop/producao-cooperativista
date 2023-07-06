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

namespace ProducaoCooperativista\Service\AkauntingDocument\Taxes;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use ProducaoCooperativista\Service\AkauntingDocument\AAkauntingDocument;

class InssIrpf extends AAkauntingDocument
{
    private AAkauntingDocument $document;
    private const ACTION_CREATE = 1;
    private const ACTION_UPDATE = 2;
    private const ACTION_IGNORE = 3;
    private int $action = self::ACTION_IGNORE;
    public function saveFromDocument(AAkauntingDocument $document): self
    {
        $this->document = $document;
        $this->coletaNaoPago();
        $this->updateItems();
        switch ($this->action) {
            case self::ACTION_CREATE:
                $this->insert();
                return $this;
            case self::ACTION_UPDATE:
                $this->setSearch('type:bill');
                parent::save();
                return $this;
        }
        return $this;
    }

    private function insert(): self
    {
        $cooperado = $this->getCooperado();
        $this
            ->setType('bill')
            ->setCategoryId((int) $_ENV['AKAUNTING_IMPOSTOS_INSS_IRRF_CATEGORY_ID'])
            ->setDocumentNumber(
                'IR_INSS_' .
                $this->dates->getDataPagamento()->format('Y-m')
            )
            ->setSearch('type:bill')
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setDueAt($this->dates->getDataPagamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber());
        parent::save();
        return $this;
    }

    private function coletaNaoPago(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->addSelect('metadata->>"$.status" AS status')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_IMPOSTOS_INSS_IRRF_CATEGORY_ID'], ParameterType::INTEGER)));

        $result = $select->executeQuery();
        $row = $result->fetchAssociative();

        if (!$row) {
            $this->action = self::ACTION_CREATE;
            return $this;
        }

        if (in_array($row['status'], ['paid', 'cancelled'])) {
            $this->action = self::ACTION_IGNORE;
            return $this;
        }

        $this->action = self::ACTION_UPDATE;
        $this->setId($row['id'])
            ->loadFromAkaunting($row['id']);
        return $this;
    }

    private function updateItems(): self
    {
        $items = $this->document->getItems();
        foreach ($items as $item) {
            $this->updateItem('INSS', $item)
                ->updateItem('IRRF', $item);
        }
        return $this;
    }

    private function updateItem(string $code, array $item): self
    {
        if ($item['item_id'] !== $this->itemsIds[$code]) {
            return $this;
        }
        $this->setItem(
            code: $code,
            name: $code . ' ' . $this->getCooperado()->getName(),
            description: $this->getItemDescription(),
            price: $item['price']
        );
        return $this;
    }

    private function getItemDescription(): string
    {
        $keyValue = [];
        $keyValue['documento'] = $this->document->getDocumentNumber();

        $names = [];
        foreach ($keyValue as $label => $value) {
            if (!is_numeric($label)) {
                $names[] = $label . ': ' . $value;
                continue;
            }
            $names[] = $value;
        }
        $name = implode(", ", $names);
        $name = ucfirst($name);
        return $name;
    }
}
