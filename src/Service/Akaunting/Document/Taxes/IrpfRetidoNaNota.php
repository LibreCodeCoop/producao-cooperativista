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

use Carbon\Carbon;

class IrpfRetidoNaNota extends Tax
{
    protected string $readableName = 'IRRF retido na nota';
    protected int $quantity = -1;
    private \DateTime $previsaoResgateSaldoIrpf;
    protected string $whoami = 'IRPF_RETIDO';
    protected string $type = 'invoice';

    protected function setUp(): self
    {
        $this->calculaPrevisaoPagamentoResgateSaldoIrpf();
        return parent::setUp();
    }

    private function calculaPrevisaoPagamentoResgateSaldoIrpf(): void
    {
        $mesResgateSaldoIrpf = $_ENV['AKAUNTING_RESGATE_SALDO_IRPF_MES_PADRAO'];
        $this->previsaoResgateSaldoIrpf = \DateTime::createFromFormat('m', (string) $mesResgateSaldoIrpf)
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $carbon = Carbon::parse($this->previsaoResgateSaldoIrpf);
        $pagamentoNoDiaUtil = 5;
        $this->previsaoResgateSaldoIrpf = $carbon->addBusinessDays($pagamentoNoDiaUtil);
    }
}
