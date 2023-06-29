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

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Service\Source\Invoices;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * @method AkauntingDocument setAmount(float $value)
 * @method float getAmount()
 * @method AkauntingDocument setCategoryId(int $value)
 * @method int getCategoryId()
 * @method AkauntingDocument setContactId(int $value)
 * @method AkauntingDocument setCooperado(CooperadoProducao $value)
 * @method CooperadoProducao getCooperado()
 * @method int getContactId()
 * @method AkauntingDocument setContactName(string $value)
 * @method string getContactName()
 * @method AkauntingDocument setContactTaxNumber(string $value)
 * @method string getContactTaxNumber()
 * @method AkauntingDocument setCurrencyCode(string $value)
 * @method string getCurrencyCode()
 * @method AkauntingDocument setCurrencyRate(int $value)
 * @method int getCurrencyRate()
 * @method AkauntingDocument setDocumentNumber(string $value)
 * @method string getDocumentNumber()
 * @method AkauntingDocument setDueAt(string $value)
 * @method string getDueAt()
 * @method AkauntingDocument setId(int $value)
 * @method int getId()
 * @method AkauntingDocument setIssuedAt(string $value)
 * @method string getIssuedAt()
 * @method AkauntingDocument setSearch(string $value)
 * @method string getSearch()
 * @method AkauntingDocument setStatus(string $value)
 * @method string getStatus()
 * @method AkauntingDocument setType(string $value)
 * @method string getType()
 */
class AkauntingDocument
{
    use MagicGetterSetterTrait;
    private float $amount = 0;
    private int $categoryId = 0;
    private int $contactId = 0;
    private string $contactName = '';
    private string $contactTaxNumber = '';
    private string $currencyCode = '';
    private int $currencyRate = 1;
    private string $documentNumber = '';
    private string $dueAt = '';
    private int $id = 0;
    private string $issuedAt = '';
    private string $search = '';
    private string $status = '';
    private string $type = '';

    private CooperadoProducao $cooperado;

    private array $notes = [];
    private array $items = [];

    /** @var int[] */
    private array $itemsIds;

    public function __construct(
        private Database $db,
        private \DateTime $inicioProximoMes,
        private Invoices $invoices
    )
    {
        $this->itemsIds = json_decode($_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS'], true);
    }

    public function setNote(string $label, $value): self
    {
        $this->notes[$label] = $value;
        return $this;
    }

    public function setItem(
        ?int $itemId = null,
        ?string $code = null,
        string $name = '',
        string $description = '',
        ?int $quantity = null,
        float $price = 0,
        float $total = 0,
        float $discount = 0,
        int $order = 0
    ): self
    {
        if ($itemId) {
            $item['item_id'] = $itemId;
        } elseif ($code) {
            $item['item_id'] = $this->itemsIds[$code];
        }
        $item['name'] = $name;
        $item['description'] = $description;
        $item['quantity'] = $quantity ? $quantity : ($price > 0 ? 1 : -1);
        $item['price'] = abs($price);
        if (!$item['price']) {
            return $this;
        }
        $item['total'] = ($total > 0 ? $total : $item['price']) * $item['quantity'];
        $item['discount'] = $discount;
        $item['order'] = $order;
        $this->items[] = $item;
        return $this;
    }

    public function insereHealthInsurance(): self
    {
        $taxNumber = $this->getContactTaxNumber();

        $cooperado = $this->getCooperado($taxNumber);

        if ($cooperado->getHealthInsurance()) {
            $this->setItem(
                itemId: $this->itemsIds['Plano'],
                name: 'Plano de saúde',
                price: -$cooperado->getHealthInsurance(),
                order: 10
            );
        }
        return $this;
    }

    public function aplicaAdiantamentos(): self
    {
        $taxNumber = $this->getContactTaxNumber();

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('amount')
            ->addSelect('document_number')
            ->addSelect('due_at')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere("metadata->>'$.status' = 'paid'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_ADIANTAMENTO_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($taxNumber)))
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->inicioProximoMes->format('Y-m'))));

        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $this->setItem(
                itemId: $this->itemsIds['desconto'],
                name: 'Adiantamento',
                description: sprintf('Número: %s, data: %s', $row['document_number'], $row['due_at']),
                price: -$row['amount'],
                order: 20
            );
        }
        return $this;
    }

    public function toArray(): array
    {
        $notes = [];
        foreach ($this->notes as $label => $value) {
            $notes[] = $label . ': ' . $value;
        }
        $items = $this->items;
        uasort($items, fn ($a, $b) => $a['order'] <=> $b['order']);
        foreach ($items as &$item) {
            unset($item['order']);
        }
        return [
            'type' => $this->getType(),
            'category_id' => $this->getCategoryId(),
            'document_number' => $this->getDocumentNumber(),
            'search' => $this->getSearch(),
            'status' => $this->getStatus(),
            'issued_at' => $this->getIssuedAt(),
            'due_at' => $this->getDueAt(),
            'id' => $this->getId(),
            'currency_code' => $this->getCurrencyCode(),
            'currency_rate' => $this->getCurrencyRate(),
            'notes' => implode("\n", $notes),
            'contact_id' => $this->getContactId(),
            'contact_name' => $this->getContactName(),
            'contact_tax_number' => $this->getContactTaxNumber(),
            'amount' => $this->getAmount(),
            'items' => array_values($items),
        ];
    }

    public function save(): void
    {
        try {
            if ($this->getId()) {
                $response = $this->invoices->sendData(
                    endpoint: '/api/documents/' . $this->getId(),
                    query: [
                        'search' => implode(' ', [
                            'type:bill',
                        ]),
                    ],
                    method: 'GET'
                );
                if ($response['data']['status'] !== 'draft') {
                    // Only is possible to update billing when is draft
                    return;
                }
                // Update if exists
                $response = $this->invoices->sendData(
                    endpoint: '/api/documents/' . $this->getId(),
                    body: $this->toArray(),
                    method: 'PATCH'
                );
            } else {
                $response = $this->invoices->sendData(
                    endpoint: '/api/documents',
                    body: $this->toArray()
                );
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $content = $response->toArray(false);
            throw new Exception(json_encode($content));
        }
        // Update local database
        $invoice = $this->invoices->fromArray($response['data']);
        $this->invoices->saveRow($invoice);
        $this->updateFrra();
    }

    private function coletaFrraNaoPago(): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_FRRA_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($this->getContactTaxNumber(), ParameterType::INTEGER)));

        $result = $select->executeQuery();
        while ($row = $result->fetchAllAssociative()) {
            $this->getCooperado()
                ->getFrraInstance()
                ->setId($row['id']);
        }
    }

    private function updateFrra(): void
    {
        $this->coletaFrraNaoPago();
    }
}
