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

use ProducaoCooperativista\Service\Akaunting\Document\Document;

class InssIrpf extends Irpf
{
    private Document $document;

    public function saveFromDocument(Document $document): self
    {
        $this->document = $document;
        $this->coletaInvoiceNaoPago();
        $this->updateItems();
        $this->save();
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
            description: 'Documento: ' . $this->document->getDocumentNumber(),
            price: $item['price']
        );
        return $this;
    }
}
