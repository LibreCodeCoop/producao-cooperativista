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
use NumberFormatter;
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
    private bool $savingFrra = false;

    private CooperadoProducao $cooperado;

    private array $notes = [];
    private array $items = [];

    /** @var int[] */
    private array $itemsIds;

    public function __construct(
        private Database $db,
        private Dates $dates,
        private NumberFormatter $numberFormatter,
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
        ?int $id = null,
        ?string $code = null,
        string $name = '',
        ?string $description = '',
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
        if ($id) {
            $item['id'] = $id;
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
        $found = array_filter($this->items, function (array $i) use ($item): bool {
            return $i['name'] === $item['name'] && $i['description'] === $item['description'];
        });
        if ($found) {
            $this->items[key($found)] = array_merge($this->items[key($found)], $item);
            return $this;
        }
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
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))));

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

    public function populateProducaoCooperativistaWithDefault(): self
    {
        $cooperado = $this->getCooperado();
        $this
            ->setType('bill')
            ->setCategoryId((int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'])
            ->setDocumentNumber(
                'PDC_' .
                $cooperado->getTaxNumber() .
                '-' .
                $this->dates->getInicio()->format('Y-m')
            )
            ->setSearch('type:bill')
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setDueAt($this->dates->getDataPagamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setNote('Data geração', $this->dates->getDataProcessamento()->format('Y-m-d'))
            ->setNote('Produção realizada no mês', $this->dates->getInicio()->format('Y-m'))
            ->setNote('Notas dos clientes pagas no mês', $this->dates->getInicioProximoMes()->format('Y-m'))
            ->setNote('Dia útil padrão de pagamento', sprintf('%sº', $this->dates->getPagamentoNoDiaUtil()))
            ->setNote('Previsão de pagamento no dia', $this->dates->getDataPagamento()->format('Y-m-d'))
            ->setNote('Base de cálculo', $this->numberFormatter->format($cooperado->getBaseProducao()))
            ->setNote('FRRA', $this->numberFormatter->format($cooperado->getFrra()))
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber())
            ->insereHealthInsurance()
            ->aplicaAdiantamentos()
            ->setItem(
                code: 'Auxílio',
                name: 'Ajuda de custo',
                price: $cooperado->getAuxilio()
            )
            ->setItem(
                code: 'bruto',
                name: 'Bruto produção',
                price: $cooperado->getBruto()
            )
            ->setTaxes()
            ->coletaProducaoNaoPaga();
        return $this;
    }

    public function setTaxes(): self
    {
        $cooperado = $this->getCooperado();
        $this
            ->setItem(
                code: 'INSS',
                name: 'INSS',
                price: $cooperado->getInss() * -1
            )
            ->setItem(
                code: 'IRRF',
                name: 'IRRF',
                price: $cooperado->getIrpf() * -1
            );
        return $this;
    }

    public function save(): void
    {
        try {
            if (!$this->getId()) {
                // Save new
                $response = $this->invoices->sendData(
                    endpoint: '/api/documents',
                    body: $this->toArray()
                );
                // If already exists a document with the same documentNumber...
                if (isset($response['errors']['document_number'])) {
                    // Search the item that have the same documentNumber to get the ID
                    $response = $this->invoices->sendData(
                        endpoint: '/api/documents',
                        query: [
                            'search' => implode(' ', [
                                'type:bill',
                                $this->getDocumentNumber()
                            ]),
                        ],
                        method: 'GET'
                    );
                    // If found the document....
                    if (!isset($response['data']) || count($response['data']) !== 1) {
                        throw new Exception(
                            "Impossible to save the document.\n" .
                            "Got an error when get the document from Akaunting OR the total of documents is different of 1.\n" .
                            "Response from API:\n" .
                            json_encode($this->toArray()) . "\n" .
                            "#############################\n" .
                            "Data to save:\n" .
                            json_encode($this->toArray())
                        );
                    }
                    // Set the ID of existing document and request again this method to handle the update
                    $this->setId($response['data'][0]['id']);
                    $this->save();
                    return;
                }
            } else {
                // Get the existing document to check if the current values is ok
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
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $content = $response->toArray(false);
            throw new Exception(json_encode($content));
        }
        // When the response have a message key is an error and we can't go ahead
        if (isset($response['message'])) {
            throw new Exception(json_encode($response));
        }
        // Update local database
        $invoice = $this->invoices->fromArray($response['data']);
        $this->invoices->saveRow($invoice);
        $this->saveFrra();
    }

    public function loadFromAkaunting(): void
    {
        $response = $this->invoices->sendData(
            endpoint: '/api/documents/' . $this->getId(),
            query: [
                'search' => implode(' ', [
                    'type:bill',
                ]),
            ],
            method: 'GET'
        );
        if ($response['data']['category_id'] === (int) $_ENV['AKAUNTING_FRRA_CATEGORY_ID']) {
            $invoice = $this->getCooperado()->getFrraInstance();
        } else {
            $invoice = $this->getCooperado()->getInvoice();
        }
        foreach ($response['data'] as $property => $value) {
            switch ($property) {
                case 'amount':
                    // The amount need to be calculated by items every time
                    $this->setAmount(0);
                    continue 2;
                case 'notes':
                    $invoice->setNotesFromString($value);
                    continue 2;
                case 'items':
                    $invoice->setItemsFromAkaunting($value['data']);
                    continue 2;
            }
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $property))));
            if (!property_exists($invoice, $property)) {
                continue;
            }
            $invoice->{'set' . ucfirst($property)}($value);
        }
    }

    private function setItemsFromAkaunting(array $items): self
    {
        foreach ($items as $item) {
            if ($item['item_id'] !== $this->itemsIds['frra']) {
                continue;
            }
            $this->setItem(
                id: $item['id'],
                itemId: $item['item_id'],
                name: $item['name'],
                description: $item['description'],
                price: $item['price']
            );
        }
        return $this;
    }

    private function setNotesFromString(string $notes): self
    {
        foreach (explode("\n", $notes) as $note) {
            list($label, $value) = explode(': ', $note);
            $this->setNote($label, $value);
        }
        return $this;
    }

    private function coletaProducaoNaoPaga(): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->in('tax_number', $select->createNamedParameter($this->getContactTaxNumber(), ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('transaction_of_month', $select->createNamedParameter($this->dates->getDataPagamento()->format('Y-m'))));

        $result = $select->executeQuery();
        $row = $result->fetchAssociative();
        if (!$row) {
            return;
        }
        $this->getCooperado($row['tax_number'])
            ->getInvoice()
            ->setId($row['id']);
    }

    private function coletaFrraNaoPago(): self
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
        $this->getCooperado()
            ->getFrraInstance()
            ->setId($row['id'])
            ->loadFromAkaunting($row['id']);
        return $this;
    }

    private function saveFrra(): self
    {
        if ($this->savingFrra) {
            return $this;
        }
        $frra = $this->getCooperado()
            ->getFrraInstance()
            ->coletaFrraNaoPago();
        $frra->savingFrra = true;
        if ($frra->getId()) {
            $frra->setSearch('type:bill');
            $frra->updateFrra();
            $frra->savingFrra = false;
            return $this;
        }
        $frra->insertFrra();
        $frra->savingFrra = false;
        return $this;
    }

    private function updateFrra(): self
    {
        $total = array_reduce($this->items, function (float $total, array $item) {
            if ($item['item_id'] === $this->itemsIds['frra']) {
                $total += $item['price'];
            }
            return $total;
        }, 0);

        $cooperado = $this->getCooperado();
        $cooperado->setIsFrra(true);
        $cooperado->setBaseProducao($total);

        $frra = $cooperado->getFrraInstance();
        $frra
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                price: $cooperado->getBaseProducao()
            )
            ->setTaxes()
            ->save();
        return $this;
    }

    private function insertFrra(): self
    {
        $cooperado = $this->getCooperado();
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
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber())
            ->setItem(
                code: 'frra',
                name: 'FRRA',
                description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                price: $cooperado->getBaseProducao()
            )
            ->setTaxes()
            ->save();
        return $this;
    }
}
