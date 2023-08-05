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

use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use NumberFormatter;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\Dates;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Provider\Akaunting\Request;
use ProducaoCooperativista\Service\Cooperado;
use ProducaoCooperativista\Service\Akaunting\Source\Documents;
use Symfony\Component\HttpClient\Exception\ClientException;
use UnexpectedValueException;

/**
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
 * @method self setId(int $value)
 * @method int getId()
 * @method self setIssuedAt(string $value)
 * @method string getIssuedAt()
 * @method self setValues(Values $value)
 * @method Values getValues()
 * @method self setSearch(string $value)
 * @method self setStatus(string $value)
 * @method string getStatus()
 * @method self setType(string $value)
 * @method string getType()
 */
abstract class ADocument
{
    use MagicGetterSetterTrait;
    protected const ACTION_CREATE = 1;
    protected const ACTION_UPDATE = 2;
    protected const ACTION_IGNORE = 3;
    protected int $action = self::ACTION_IGNORE;
    protected float $amount = 0;
    protected int $categoryId = 0;
    protected int $contactId = 0;
    protected string $contactName = '';
    protected ?string $contactTaxNumber = null;
    protected string $currencyCode = '';
    protected int $currencyRate = 1;
    protected string $documentNumber = '';
    protected ?DateTime $dueAt = null;
    protected int $id = 0;
    protected string $issuedAt = '';
    protected string $search = '';
    protected string $status = '';
    protected string $type = '';
    protected string $whoami = '';
    protected Values $values;
    protected bool $loadedFromAkaunting = false;

    protected array $notes = [];
    protected array $items = [];

    /** @var int[] */
    protected array $itemsIds;

    public function __construct(
        protected Database $db,
        protected Dates $dates,
        protected Documents $documents,
        protected Request $request,
        protected ?int $anoFiscal = null,
        protected ?NumberFormatter $numberFormatter = null,
        protected ?Cooperado $cooperado = null,
    ) {
        $this->itemsIds = json_decode($_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS'], true);
        $this->setValues(new Values(
            anoFiscal: $anoFiscal,
            cooperado: $cooperado
        ));
        $this->setUp();
    }

    protected function setUp(): self
    {
        $this->coletaInvoiceNaoPago();
        return $this;
    }

    protected function changed(): self
    {
        if ($this->action === self::ACTION_CREATE) {
            return $this;
        }
        $this->action = self::ACTION_UPDATE;
        return $this;
    }

    protected function getDocumentNumber(): string
    {
        if (empty($this->documentNumber)) {
            $this->setDocumentNumber(($this->whoami ?? $this->type) . '_' . $this->dueAt->format('Y-m-d'));
        }
        return $this->documentNumber;
    }

    private function getSearch(): string
    {
        if ($this->search) {
            return $this->search;
        }
        $this->search = 'type:' . $this->getType();
        return $this->search;
    }

    public function setNote(string $label, $value): self
    {
        $current = $this->notes[$label] ?? null;
        if ($value !== $current) {
            $this->changed();
            $this->notes[$label] = $value;
        }
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
    ): self {
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
        $item['price'] = round($item['price']);
        $item['total'] = round($item['total']);
        $item['discount'] = $discount;
        $item['order'] = $order;
        $found = array_filter($this->items, function (array $i) use ($item): bool {
            return $i['name'] === $item['name'] && $i['description'] === $item['description'];
        });
        if ($found) {
            $items = array_merge($this->items[key($found)], $item);
            if (array_diff($this->items[key($found)], $items)) {
                $this->changed();
                $this->items[key($found)] = $items;
            }
            return $this;
        }
        $this->items[] = $item;
        return $this;
    }

    public function getItems(): array
    {
        return $this->items;
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
            'due_at' => $this->getDueAt()->format('Y-m-d H:i:s'),
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
        if ($this->action === self::ACTION_IGNORE) {
            return $this;
        }
        try {
            if (!$this->getId()) {
                // Save new
                $response = $this->request->send(
                    endpoint: '/api/documents',
                    body: $this->toArray()
                );
                // If already exists a document with the same documentNumber...
                if (isset($response['errors']['document_number'])) {
                    // Search the item that have the same documentNumber to get the ID
                    $response = $this->request->send(
                        endpoint: '/api/documents',
                        query: [
                            'search' => implode(' ', [
                                $this->getSearch(),
                                $this->getDocumentNumber()
                            ]),
                        ],
                        method: 'GET'
                    );
                    $this->request->handleError($response);
                    // Set the ID of existing document and request again this method to handle the update
                    $this->setId($response['data'][0]['id']);
                    $this->save();
                    return $this;
                }
            } else {
                // Update if exists
                $response = $this->request->send(
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
        $this->request->handleError($response);
        // Update local database
        $document = $this->documents->fromArray($response['data']);
        $this->documents->saveRow($document);
        return $this;
    }

    protected function coletaInvoiceNaoPago(): self
    {
        if ($this->loadedFromAkaunting) {
            return $this;
        }
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->addSelect('metadata->>"$.status" AS status')
            ->addSelect('due_at')
            ->from('invoices')
            ->where($select->expr()->eq('document_number', $select->createNamedParameter($this->getDocumentNumber())));

        $result = $select->executeQuery();
        $row = $result->fetchAssociative();

        if (!$row) {
            $this->action = self::ACTION_CREATE;
            return $this;
        }

        if (in_array($row['status'], ['paid', 'cancelled'])) {
            return $this;
        }

        $this->setId($row['id'])
            ->setDueAt($row['due_at'])
            ->loadFromAkaunting($row['id']);
        return $this;
    }

    protected function loadFromAkaunting(): void
    {
        if ($this->loadedFromAkaunting) {
            return;
        }
        $response = $this->request->send(
            endpoint: '/api/documents/' . $this->getId(),
            query: [
                'search' => implode(' ', [
                    'type:bill',
                ]),
            ],
            method: 'GET'
        );
        foreach ($response['data'] as $property => $value) {
            $property = $this->camelize($property);
            $methodName = 'set' . ucfirst($property);
            if (method_exists($this, $methodName)) {
                $this->$methodName($value);
                continue;
            }
            if (property_exists($this, $property)) {
                $this->$methodName($value);
            }
        }
        $document = $this->documents->fromArray($response['data']);
        $this->documents->saveRow($document);
        $this->loadedFromAkaunting = true;
    }

    private function camelize(string $text): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $text))));
    }

    private function setDueAt(string $dateTime): self
    {
        $dateTime = preg_replace('/-\d{2}:\d{2}$/', '', $dateTime);
        $dateTime = str_replace('T', ' ', $dateTime);
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);

        return $this->changeDueAt($dateTime);
    }

    protected function changeDueAt(DateTime $dueAt): self
    {
        $current = $this->dueAt;
        if ($current instanceof DateTime) {
            $time = $current->format('H:i:s');
            // A data é zero quando é um item novo
            if ($time !== '00:00:00' && $current !== $this->dueAt) {
                $this->changed();
            }
        }
        $this->dueAt = $dueAt;
        return $this;
    }

    protected function getDueAt(): DateTime
    {
        if (!$this->dueAt instanceof DateTime) {
            throw new UnexpectedValueException('DueAt não inicializado');
        }
        return $this->dueAt;
    }

    protected function setStatus(string $value): self
    {
        if ($value !== 'draft') {
            $this->action = self::ACTION_IGNORE;
        }
        $this->status = $value;
        return $this;
    }

    private function setAmount(): void
    {
        // The amount need to be calculated by items every time
        $this->amount = 0;
    }

    private function setItems($value): self
    {
        foreach ($value['data'] as $item) {
            $this->setItem(
                id: $item['id'],
                itemId: $item['item_id'],
                name: $item['name'],
                description: $item['description'] ?? '',
                price: $item['price']
            );
        }
        return $this;
    }

    private function setNotes($notes): self
    {
        if (empty($notes)) {
            return $this;
        }
        foreach (explode("\n", $notes) as $note) {
            if (!str_contains($note, ': ')) {
                continue;
            }
            list($label, $value) = explode(': ', $note);
            $this->notes[$label] = $value;
        }
        return $this;
    }
}
