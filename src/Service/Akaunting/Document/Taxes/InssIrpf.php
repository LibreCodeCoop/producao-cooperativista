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

use App\Service\Akaunting\Document\ADocument;
use Collator;
use UnexpectedValueException;

class InssIrpf extends Tax
{
    protected string $whoami = 'INSS_IRRF';
    protected string $readableName = 'IRRF';
    protected int $quantity = -1;
    private ADocument $document;

    protected function setUp(): self
    {
        try {
            $this->getDueAt();
        } catch (UnexpectedValueException $e) {
            $this->changeDueAt($this->dates->getDataPagamento());
        }
        return parent::setUp();
    }

    public function saveFromDocument(ADocument $document): self
    {
        $this->document = $document;
        $this->updateItems();
        $this->save();
        return $this;
    }

    private function updateItems(): self
    {
        $this->removeCurrentCooperadoItems();

        $codesByItemId = [
            $this->getItemId('INSS') => 'INSS',
            $this->getItemId('IRRF') => 'IRRF',
        ];

        foreach ($this->document->getItems() as $item) {
            $code = $codesByItemId[$item['item_id'] ?? 0] ?? null;
            if (!$code) {
                continue;
            }

            $this->setItem(
                code: $code,
                name: $this->getTaxItemName($code),
                description: 'Documento: ' . $this->document->getDocumentNumber(),
                price: $item['price']
            );
        }

        $this->sortItemsByCooperadoName();

        return $this;
    }

    private function removeCurrentCooperadoItems(): self
    {
        $itemNames = [
            $this->getTaxItemName('INSS'),
            $this->getTaxItemName('IRRF'),
        ];

        $items = array_values(array_filter($this->items, fn (array $item): bool => !in_array(
            trim((string) ($item['name'] ?? '')),
            $itemNames,
            true
        )));

        if (count($items) === count($this->items)) {
            return $this;
        }

        $this->changed();
        $this->items = $items;

        return $this;
    }

    private function getTaxItemName(string $code): string
    {
        return $code . ' ' . $this->getCooperado()->getName();
    }

    private function sortItemsByCooperadoName(): self
    {
        $items = $this->items;
        $locale = (string) getenv('LOCALE');
        $collator = class_exists(Collator::class) ? new Collator($locale ?: 'pt_BR') : null;

        usort($items, function (array $left, array $right) use ($collator): int {
            $comparison = $this->compareNames(
                $this->extractCooperadoName((string) ($left['name'] ?? '')),
                $this->extractCooperadoName((string) ($right['name'] ?? '')),
                $collator
            );

            if ($comparison !== 0) {
                return $comparison;
            }

            return $this->compareNames(
                (string) ($left['name'] ?? ''),
                (string) ($right['name'] ?? ''),
                $collator
            );
        });

        foreach ($items as $index => &$item) {
            $item['order'] = $index;
        }
        unset($item);

        $this->items = $items;

        return $this;
    }

    private function compareNames(string $left, string $right, ?Collator $collator): int
    {
        if ($collator instanceof Collator) {
            return $collator->compare($left, $right);
        }

        return strnatcasecmp($left, $right);
    }

    private function extractCooperadoName(string $name): string
    {
        $name = trim($name);
        return preg_replace('/^(INSS|IRRF)\s+/u', '', $name) ?? $name;
    }
}
