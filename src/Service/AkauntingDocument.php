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

use Exception;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Service\Source\Invoices;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * @method AkautingDocument setAccountId(int $value)
 * @method int getAccountId()
 * @method AkautingDocument setAmount(float $value)
 * @method float getAmount()
 * @method AkautingDocument setCategoryId(int $value)
 * @method int getCategoryId()
 * @method AkautingDocument setContactId(int $value)
 * @method int getContactId()
 * @method AkautingDocument setContactName(string $value)
 * @method string getContactName()
 * @method AkautingDocument setContactTaxNumber(string $value)
 * @method string getContactTaxNumber()
 * @method AkautingDocument setCurrencyCode(string $value)
 * @method string getCurrencyCode()
 * @method AkautingDocument setCurrencyRate(int $value)
 * @method int getCurrencyRate()
 * @method AkautingDocument setDocumentNumber(string $value)
 * @method string getDocumentNumber()
 * @method AkautingDocument setDueAt(string $value)
 * @method string getDueAt()
 * @method AkautingDocument setIssuedAt(string $value)
 * @method string getIssuedAt()
 * @method AkautingDocument setSearch(string $value)
 * @method string getSearch()
 * @method AkautingDocument setStatus(string $value)
 * @method string getStatus()
 * @method AkautingDocument setType(string $value)
 * @method string getType()
 */
class AkautingDocument
{
    use MagicGetterSetterTrait;
    private int $accountId = 0;
    private float $amount = 0;
    private int $categoryId = 0;
    private int $contactId = 0;
    private string $contactName = '';
    private string $contactTaxNumber = '';
    private string $currencyCode = '';
    private int $currencyRate = 1;
    private string $documentNumber = '';
    private string $dueAt = '';
    private string $issuedAt = '';
    private string $search = '';
    private string $status = '';
    private string $type = '';

    private array $notes = [];
    private array $items = [];

    public function setNote(string $label, $value): self
    {
        $this->notes[$label] = $value;
        return $this;
    }

    public function setItem(
        int $itemId,
        string $name = '',
        string $description = '',
        ?int $quantity = null,
        float $price = 0,
        float $total = 0,
        float $discount = 0,
        int $order = 0
    ): self
    {
        $item['item_id'] = $itemId;
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
            'account_id' => $this->getAccountId(),
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
}
