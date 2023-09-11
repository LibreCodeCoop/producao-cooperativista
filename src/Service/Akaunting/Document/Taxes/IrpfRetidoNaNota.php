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

use UnexpectedValueException;

class IrpfRetidoNaNota extends Tax
{
    protected string $whoami = 'IRPF_RETIDO';
    protected string $readableName = 'IRPF retido na nota';
    protected string $type = 'invoice';
    protected int $quantity = 1;

    protected function setUp(): self
    {
        try {
            $this->getDueAt();
        } catch (UnexpectedValueException $e) {
            $this->changeDueAt(\DateTime::createFromFormat('m', (string) getenv('AKAUNTING_RESGATE_SALDO_IRPF_MES_PADRAO')));
        }
        return parent::setUp();
    }

    public function saveMonthTaxes(): self
    {
        $total = $this->getTotalRetainedOfMonth();
        $this
            ->setItem(
                itemId: (int) getenv('AKAUNTING_IMPOSTOS_ITEM_ID'),
                name: $this->readableName,
                description: 'Impostos retidos do mês ' . $this->dates->getInicioProximoMes()->format('Y-m'),
                price: $total * $this->quantity
            );
        $this->save();
        return $this;
    }
}
