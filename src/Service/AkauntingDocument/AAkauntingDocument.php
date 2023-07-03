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

use Exception;
use NumberFormatter;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Service\Cooperado;
use ProducaoCooperativista\Service\Dates;
use ProducaoCooperativista\Service\Producao;
use ProducaoCooperativista\Service\Source\Invoices;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * @method self setAmount(float $value)
 * @method float getAmount()
 * @method self setCategoryId(int $value)
 * @method int getCategoryId()
 * @method self setContactId(int $value)
 * @method self setCooperado(Cooperado $value)
 * @method Cooperado getCooperado()
 * @method int getContactId()
 * @method self setContactName(string $value)
 * @method string getContactName()
 * @method self setContactTaxNumber(string $value)
 * @method string getContactTaxNumber()
 * @method self setCurrencyCode(string $value)
 * @method string getCurrencyCode()
 * @method self setCurrencyRate(int $value)
 * @method int getCurrencyRate()
 * @method self setDocumentNumber(string $value)
 * @method string getDocumentNumber()
 * @method self setDueAt(string $value)
 * @method string getDueAt()
 * @method self setId(int $value)
 * @method int getId()
 * @method self setIssuedAt(string $value)
 * @method string getIssuedAt()
 * @method self setValues(Values $value)
 * @method Values getValues()
 * @method self setSearch(string $value)
 * @method string getSearch()
 * @method self setStatus(string $value)
 * @method string getStatus()
 * @method self setType(string $value)
 * @method string getType()
 */
class AAkauntingDocument
{
    use MagicGetterSetterTrait;
    protected float $amount = 0;
    protected int $categoryId = 0;
    protected int $contactId = 0;
    protected string $contactName = '';
    protected string $contactTaxNumber = '';
    protected string $currencyCode = '';
    protected int $currencyRate = 1;
    protected string $documentNumber = '';
    protected string $dueAt = '';
    protected int $id = 0;
    protected string $issuedAt = '';
    protected string $search = '';
    protected string $status = '';
    protected string $type = '';
    protected Values $values;

    protected array $notes = [];
    protected array $items = [];

    /** @var int[] */
    protected array $itemsIds;

    public function __construct(
        protected ?int $anoFiscal,
        protected Database $db,
        protected Dates $dates,
        protected NumberFormatter $numberFormatter,
        protected Invoices $invoices,
        protected Cooperado $cooperado,
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

    protected function setTaxes(): self
    {
        $values = $this->getValues();
        $this
            ->setItem(
                code: 'INSS',
                name: 'INSS',
                price: $values->getInss() * -1
            )
            ->setItem(
                code: 'IRRF',
                name: 'IRRF',
                price: $values->getIrpf() * -1
            );
        return $this;
    }

    public function save(): self
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
                    return $this;
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
                    return $this;
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
        return $this;
    }

    protected function loadFromAkaunting(): void
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
        foreach ($response['data'] as $property => $value) {
            switch ($property) {
                case 'amount':
                    // The amount need to be calculated by items every time
                    $this->setAmount(0);
                    continue 2;
                case 'notes':
                    $this->setNotesFromString($value);
                    continue 2;
                case 'items':
                    $this->setItemsFromAkaunting($value['data']);
                    continue 2;
            }
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $property))));
            if (!property_exists($this, $property)) {
                continue;
            }
            $this->{'set' . ucfirst($property)}($value);
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
}
