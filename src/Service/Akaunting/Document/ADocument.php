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

namespace App\Service\Akaunting\Document;

use DateTime;
use Exception;
use App\Helper\Dates;
use App\Helper\MagicGetterSetterTrait;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Cooperado;
use App\Service\Akaunting\Source\Documents;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
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

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected Dates $dates,
        protected Documents $documents,
        protected Request $request,
        protected DocumentConfiguration $documentConfiguration,
        protected ?int $anoFiscal = null,
        protected ?int $mes = null,
        protected ?NumberFormatter $numberFormatter = null,
        protected ?Cooperado $cooperado = null,
    ) {
        $this->setValues(new Values(
            anoFiscal: $anoFiscal,
            mes: $mes,
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

    public function setNote(string $label, string $value): self
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
        $item = [];
        if ($itemId) {
            $item['item_id'] = $itemId;
        } elseif ($code) {
            $item['item_id'] = $this->getItemId($code);
        }
        if ($id) {
            $item['id'] = $id;
        }
        $item['name'] = $name;
        $item['description'] = $description;
        if ($total < 0 || $price < 0) {
            $item['quantity'] = -1;
        } elseif (empty($quantity)) {
            $item['quantity'] = 1;
        } else {
            $item['quantity'] = $quantity;
        }
        $item['price'] = abs($price);
        if (!$item['price']) {
            return $this;
        }
        $item['total'] = ($total > 0 ? $total : $item['price']) * $item['quantity'];
        $item['price'] = round($item['price'], 2);
        $item['total'] = round($item['total'], 2);
        $item['discount'] = $discount;
        $item['order'] = $order;
        $found = array_filter($this->items, fn (array $i): bool => $i['name'] === $item['name'] && $i['description'] === $item['description']);
        if ($found) {
            $mergedItem = array_merge($this->items[key($found)], $item);
            if (array_diff($this->items[key($found)], $mergedItem)) {
                $this->changed();
                $this->items[key($found)] = $mergedItem;
            }
            return $this;
        } else {
            $this->changed();
        }
        $this->items[] = $item;
        return $this;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return int[]
     */
    public function getItemsIds(): array
    {
        return $this->documentConfiguration->getDocumentItemIds();
    }

    protected function getItemId(string $code): int
    {
        return $this->documentConfiguration->getDocumentItemId($code);
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
        $dueAt = $this->getDueAt()->format('Y-m-d\TH:i:s');
        if ($this->getIssuedAt() > $dueAt) {
            $this->setIssuedAt($dueAt);
        }
        return [
            'type' => $this->getType(),
            'category_id' => $this->getCategoryId(),
            'document_number' => $this->getDocumentNumber(),
            'search' => $this->getSearch(),
            'status' => $this->getStatus(),
            'issued_at' => $this->getIssuedAt(),
            'due_at' => $dueAt,
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
                price: abs($values->getInss()) * -1
            )
            ->setItem(
                code: 'IRRF',
                name: 'IRRF',
                price: abs($values->getIrpf()) * -1
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
                    $matches = array_filter(
                        $response['data'] ?? [],
                        fn ($row): bool => is_array($row)
                            && ($row['document_number'] ?? '') === $this->getDocumentNumber()
                    );
                    // Search result ordering must not decide which financial record to update.
                    $existing = count($matches) === 1 ? reset($matches) : null;
                    if (!is_array($existing) || empty($existing['id'])) {
                        throw new \RuntimeException(
                            'Documento já existe no Akaunting, mas a consulta não retornou um registro exato e único. Verifique o cadastro antes de tentar novamente.'
                        );
                    }
                    if (in_array($existing['status'] ?? '', ['paid', 'cancelled'], true)) {
                        throw new \RuntimeException(
                            'Documento já finalizado no Akaunting. A atualização foi bloqueada.'
                        );
                    }
                    // Document-number equality alone is not sufficient:
                    // never update another company, supplier or document type.
                    $this->assertSameDocumentIdentity($existing);
                    $this->setId((int) $existing['id']);
                    $this->save();
                    return $this;
                }
            } else {
                // Staged IDs are only hints: the document may have been paid,
                // cancelled or reassigned since it was imported.
                $this->assertRemoteDocumentCanBeUpdated();
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
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new \RuntimeException(
                'Akaunting processou a operação, mas não retornou os dados do documento. Verifique o documento existente antes de reexecutar.'
            );
        }
        // Update local database
        $document = $this->documents->fromArray($response['data']);
        $this->documents->saveRow($document);
        return $this;
    }

    /**
     * Fetch the current remote state before a PATCH. Never trust an earlier
     * local snapshot, a prior successful GET, or a duplicate-number conflict.
     */
    private function assertRemoteDocumentCanBeUpdated(): void
    {
        $response = $this->request->send(
            endpoint: '/api/documents/' . $this->getId(),
            query: ['search' => 'type:' . $this->getType()],
            method: 'GET',
        );
        $this->request->handleError($response);
        $existing = $response['data'] ?? null;
        if (!is_array($existing)
            || (int) ($existing['id'] ?? 0) !== $this->getId()
            || ($existing['document_number'] ?? null) !== $this->getDocumentNumber()) {
            throw new \RuntimeException(
                'Identificação remota do documento divergente da referência local. A atualização foi bloqueada.'
            );
        }

        if (in_array($existing['status'] ?? null, ['paid', 'cancelled'], true)) {
            throw new \RuntimeException(
                'Documento já finalizado no Akaunting. A atualização foi bloqueada.'
            );
        }
        $this->assertSameDocumentIdentity($existing);
    }

    /**
     * A duplicate-number response is not authorization to update the record.
     * Require a complete, matching tenant/type/contact identity.
     *
     * @param array<string, mixed> $existing
     */
    private function assertSameDocumentIdentity(array $existing): void
    {
        $companyId = (int) getenv('AKAUNTING_COMPANY_ID');
        if ($companyId <= 0
            || (int) ($existing['company_id'] ?? 0) !== $companyId
            || (string) ($existing['type'] ?? '') !== $this->getType()
            || $this->getContactId() <= 0
            || (int) ($existing['contact_id'] ?? 0) !== $this->getContactId()) {
            throw new \RuntimeException(
                'Documento existente no Akaunting tem empresa, tipo ou contato divergente. Atualização bloqueada.'
            );
        }
        if (!in_array((string) ($existing['status'] ?? ''), ['draft', 'sent'], true)) {
            throw new \RuntimeException(
                'Documento tem situação não habilitada para atualização. Confira o estado no Akaunting.'
            );
        }
    }

    protected function coletaInvoiceNaoPago(): self
    {
        if ($this->loadedFromAkaunting) {
            return $this;
        }
        $select = $this->entityManager->getConnection()->createQueryBuilder();
        $select->select('i.id')
            ->addSelect('i.tax_number')
            ->addSelect('i.document_number')
            ->addSelect("JSON_UNQUOTE(JSON_EXTRACT(i.metadata, '$.status')) AS status")
            ->addSelect('i.due_at')
            ->from('invoices', 'i')
            ->where($select->expr()->eq('i.document_number', $select->createNamedParameter($this->getDocumentNumber())));

        $row = $select->fetchAssociative();

        if (!$row) {
            $this->action = self::ACTION_CREATE;
            return $this;
        }

        if (in_array($row['status'], ['paid', 'cancelled'])) {
            return $this;
        }

        $this->setId($row['id'])
            ->setDueAt($row['due_at'])->loadFromAkaunting();
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
        $this->request->handleError($response);
        $remote = $response['data'] ?? null;
        // Never populate a document from a remote ID that no longer points
        // to the expected source; this would poison subsequent comparisons.
        $companyId = (int) getenv('AKAUNTING_COMPANY_ID');
        if (!is_array($remote)
            || $companyId <= 0
            || (int) ($remote['id'] ?? 0) !== $this->getId()
            || ($remote['document_number'] ?? null) !== $this->getDocumentNumber()
            || (int) ($remote['company_id'] ?? 0) !== $companyId
            || ($this->getType() !== '' && ($remote['type'] ?? null) !== $this->getType())
            || ($this->getContactId() > 0 && (int) ($remote['contact_id'] ?? 0) !== $this->getContactId())) {
            throw new \RuntimeException(
                'Identificação remota do documento divergente da referência local. A leitura foi bloqueada.'
            );
        }
        if (in_array($remote['status'] ?? null, ['paid', 'cancelled'], true)) {
            throw new \RuntimeException(
                'Documento já finalizado no Akaunting. Atualize os dados locais antes de recalcular.'
            );
        }
        if (!in_array($remote['status'] ?? null, ['draft', 'sent'], true)) {
            throw new \RuntimeException(
                'Documento tem situação não reconhecida durante a reconciliação.'
            );
        }
        foreach ($remote as $property => $value) {
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
        $document = $this->documents->fromArray($remote);
        $this->documents->saveRow($document);
        $this->loadedFromAkaunting = true;
    }

    private function camelize(string $text): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $text))));
    }

    private function setDueAt(string $dateTime): self
    {
        $dateTimeSanitized = preg_replace('/-\d{2}:\d{2}$/', '', $dateTime);
        $dateTimeSanitized = str_replace('T', ' ', $dateTimeSanitized);
        $dateTimeObject = DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeSanitized);
        if (!$dateTimeObject) {
            $dateTimeObject = DateTime::createFromFormat('Y-m-d', $dateTimeSanitized);
        }
        if (!$this->dueAt instanceof DateTime) {
            throw new UnexpectedValueException('DueAt inválido: ' . $dateTimeSanitized);
        }

        return $this->changeDueAt($dateTimeObject);
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
                price: $item['price'] ?? 0,
                total: $item['total'] ?? 0,
                quantity: $item['quantity'] ?? null,
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
            [$label, $value] = explode(': ', $note);
            $this->notes[$label] = $value;
        }
        return $this;
    }
}
