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

namespace App\Service\Akaunting\Document\Taxes;

use App\Entity\Producao\Tax as EntityTax;
use Doctrine\DBAL\ParameterType;
use Exception;
use App\Service\Akaunting\Document\ADocument;
use stdClass;

class Tax extends ADocument
{
    protected stdClass $taxData;
    protected string $whoami = 'TAX';
    protected string $readableName = 'Tax';
    protected int $quantity = 1;
    protected string $type = 'bill';
    protected float $totalBrutoNotasClientes;

    public function saveMonthTaxes(): self
    {
        $aPagar = $this->pegaValorAPagar();
        if ($aPagar === 0) {
            return $this;
        }
        $this
            ->setItem(
                itemId: (int) getenv('AKAUNTING_IMPOSTOS_ITEM_ID'),
                name: $this->readableName,
                description: 'Impostos do mês ' . $this->dates->getInicioProximoMes()->format('Y-m'),
                price: $aPagar * $this->quantity
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
            parent::save();
            return $this;
        }
        return $this;
    }

    private function pegaValorAPagar(): float
    {
        $retido = $this->getTotalRetainedOfMonth();
        $percentualImposto = $this->getPercentualDoImposto();
        $totalNotas = $this->getTotalBrutoNotasClientes();
        $totalImpostoAPagar = $totalNotas * $percentualImposto / 100;
        $diferenca = $totalImpostoAPagar - $retido;
        return $diferenca;
    }

    private function getPercentualDoImposto(): float
    {
        $tax = $this->entityManager->getRepository(EntityTax::class)->find($this->taxData->taxId);
        return $tax->getRate();
    }

    protected function setUp(): self
    {
        $this->taxData = json_decode(getenv('AKAUNTING_IMPOSTOS_' . $this->whoami));
        return parent::setUp();
    }

    protected function getTotalRetainedOfMonth(): float
    {
        $query = <<<SQL
            SELECT SUM(jt.amount) as irpf
            FROM invoices i ,
                JSON_TABLE(i.metadata, '$.item_taxes.data[*]' COLUMNS (
                    id INTEGER PATH '$.tax_id',
                    amount DOUBLE PATH '$.amount'
                )) jt
            WHERE jt.id = :tax_id
            AND i.transaction_of_month = :ano_mes
            SQL;
        $stmt = $this->entityManager->getConnection()->executeQuery($query, [
            'ano_mes' => $this->dates->getInicioProximoMes()->format('Y-m'),
            'tax_id' => $this->taxData->taxId, ParameterType::INTEGER,
        ]);
        $total = (float) $stmt->fetchOne();
        return $total;
    }

    private function insert(): self
    {
        $contact = $this->getContact();

        $this
            ->setCategoryId($this->taxData->categoryId)
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
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
