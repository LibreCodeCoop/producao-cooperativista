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

namespace ProducaoCooperativista\Service\Akaunting\Document\Taxes;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use ProducaoCooperativista\Service\Akaunting\Document\Document;
use stdClass;

class Tax extends Document
{
    protected const ACTION_CREATE = 1;
    protected const ACTION_UPDATE = 2;
    protected const ACTION_IGNORE = 3;
    protected int $action = self::ACTION_IGNORE;
    protected stdClass $taxData;
    protected string $whoami = 'TAX';
    protected string $readableName = 'Tax';
    protected int $quantity = 1;

    public function saveMonthTaxes(): self
    {
        $total = $this->getTotalRetainedOfMonth();
        $this->coletaInvoiceNaoPago();
        $this
            ->setItem(
                itemId: (int) $_ENV['AKAUNTING_IMPOSTOS_ITEM_ID'],
                name: $this->readableName,
                description: 'Impostos do mês ' . $this->dates->getInicioProximoMes()->format('Y-m'),
                price: $total * $this->quantity
            );
        $this->save();
        return $this;
    }

    public function save(): self
    {
        if ($this->action === self::ACTION_CREATE) {
            $this->insert();
            return $this;
        }
        if ($this->action === self::ACTION_UPDATE) {
            $this->setSearch('type:bill');
            parent::save();
            return $this;
        }
        return $this;
    }

    protected function setUp(): self
    {
        $this->taxData = json_decode($_ENV['AKAUNTING_IMPOSTOS_' . $this->whoami]);
        return $this;
    }

    protected function coletaInvoiceNaoPago(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->addSelect('metadata->>"$.status" AS status')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))))
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter($this->taxData->categoryId, ParameterType::INTEGER)));

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

    private function getTotalRetainedOfMonth(): float
    {
        $stmt = $this->db->getConnection()->prepare(
            <<<SQL
            SELECT SUM(jt.amount) as irpf
            FROM invoices i ,
                JSON_TABLE(i.metadata, '$.item_taxes.data[*]' COLUMNS (
                    id INTEGER PATH '$.tax_id',
                    amount DOUBLE PATH '$.amount'
                )) jt
            WHERE jt.id = :tax_id
            AND i.transaction_of_month = :ano_mes
            SQL
        );
        $stmt->bindValue('ano_mes', $this->dates->getInicioProximoMes()->format('Y-m'));
        $stmt->bindValue('tax_id', $this->taxData->taxId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        $total = (float) $result->fetchOne();
        return $total;
    }

    private function insert(): self
    {
        $contact = $this->getContact();

        $this
            ->setType('bill')
            ->setCategoryId($this->taxData->categoryId)
            ->setDocumentNumber(
                $this->whoami . '_' .
                $this->dates->getDataPagamento()->format('Y-m')
            )
            ->setSearch('type:bill')
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setDueAt($this->dates->getDataPagamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setContactId($contact['id'])
            ->setContactName($contact['name'])
            ->setContactTaxNumber($contact['tax_number'] ?? '');
        parent::save();
        return $this;
    }

    private function getContact(): array
    {
        $response = $this->request->send(
            endpoint: '/api/contacts/' . $this->taxData->contactId,
            query: [
                'search' => implode(' ', [
                    'type:vendor',
                ]),
            ],
            method: 'GET'
        );
        if (!isset($response['data'])) {
            throw new Exception(
                "Impossible to handle contact to insert bill of type {$this->readableName}.\n" .
                "Got an error when get the contact with ID: {$this->taxData->contactId}.\n" .
                "Response from API:\n" .
                json_encode($response)
            );
        }
        return $response['data'];
    }
}
