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

namespace App\Helper;

use Carbon\Carbon;
use Cmixin\BusinessDay;
use DateTime;

/**
 * @method DateTime getInicio()
 * @method DateTime getFim()
 * @method DateTime getDataPagamento()
 * @method DateTime getDataProcessamento()
 * @method DateTime getFimProximoMes()
 * @method DateTime getPrevisaoPagamentoFrra()
 * @method int getPagamentoNoDiaUtil()
 */
class Dates
{
    use MagicGetterSetterTrait;
    private int $diasUteis = 0;
    private int $pagamentoNoDiaUtil = 5;
    private DateTime $inicio;
    private DateTime $fim;
    private DateTime $dataPagamento;
    private DateTime $dataProcessamento;
    private DateTime $inicioProximoMes;
    private DateTime $fimProximoMes;
    private DateTime $previsaoPagamentoFrra;

    public function __construct(
        private string $locationHolydays = 'br-national',
        private int $pagamentoFrraMesPadrao = 12,
        private DateTime $now = new DateTime(),
    ) {
        BusinessDay::enable(\Carbon\Carbon::class, $this->locationHolydays);
        $this->calculaPrevisaoPagamentoFrra();
    }

    private function calculaPrevisaoPagamentoFrra(): void
    {
        $agora = $this->hoje();
        $ano = (int) $agora->format('Y');
        if ((int) $agora->format('n') >= $this->pagamentoFrraMesPadrao) {
            ++$ano;
        }
        $this->previsaoPagamentoFrra = \DateTime::createFromFormat('!Y-m', $ano . '-' . (string) $this->pagamentoFrraMesPadrao)
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $carbon = Carbon::parse($this->previsaoPagamentoFrra);
        $this->previsaoPagamentoFrra = $carbon->addBusinessDays($this->pagamentoNoDiaUtil);
    }

    public function hoje(): DateTime
    {
        return $this->now;
    }

    public function setInicio(DateTime $inicio): void
    {
        $this->inicio = (clone $inicio)
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $fim = clone $this->inicio;
        $this->fim = $fim->modify('last day of this month')
            ->setTime(23, 59, 59);

        $this->inicioProximoMes = (clone $this->inicio)->modify('first day of next month');
        $this->fimProximoMes = (clone $fim)->modify('last day of next month');
        unset($this->dataPagamento, $this->dataProcessamento);
        $this->diasUteis = 0;
    }

    public function setDiaUtilPagamento(int $dia): void
    {
        $this->pagamentoNoDiaUtil = $dia;
        unset($this->dataPagamento, $this->dataProcessamento);
        $this->calculaPrevisaoPagamentoFrra();
    }

    public function getDataPagamento(): DateTime
    {
        try {
            return $this->dataPagamento;
        } catch (\Throwable) {
            $inicoMes = (clone $this->inicioProximoMes)->modify('first day of next month');
            $carbon = Carbon::parse($inicoMes);
            $dataPagamento = $carbon->addBusinessDays($this->pagamentoNoDiaUtil);
            $this->dataProcessamento = new DateTime();
            $string = $dataPagamento->format('Y-m-d H:i:s');
            if ($string > $this->dataProcessamento->format('Y-m-d H:i:s')) {
                $this->dataPagamento = new DateTime($string);
            } else {
                $this->dataPagamento = $this->dataProcessamento;
            }
        }
        return $this->dataPagamento;
    }

    public function getDataProcessamento(): DateTime
    {
        $this->getDataPagamento();
        return $this->dataProcessamento;
    }

    public function setDiasUteis(int $diasUteis): void
    {
        $this->diasUteis = $diasUteis;
    }

    public function getDiasUteisNoMes(): int
    {
        if ($this->diasUteis === 0) {
            $date = Carbon::getMonthBusinessDays($this->getInicio());
            $this->diasUteis = count($date);
        }
        return $this->diasUteis;
    }
}
