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

use Carbon\Carbon;
use Cmixin\BusinessDay;
use DateTime;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;

/**
 * @method DateTime getInicio()
 * @method DateTime getFim()
 * @method DateTime getDataPagamento()
 * @method DateTime getDataProcessamento()
 * @method DateTime getFimProximoMes()
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

    public function __construct()
    {
        BusinessDay::enable('Carbon\Carbon', $_ENV['HOLYDAYS_LIST'] ?? 'br-national');
    }

    public function setInicio(DateTime $inicio): void
    {
        $this->inicio = $inicio
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $fim = clone $inicio;
        $this->fim = $fim->modify('last day of this month')
            ->setTime(23, 59, 59);

        $this->inicioProximoMes = (clone $inicio)->modify('first day of next month');
        $this->fimProximoMes = (clone $fim)->modify('last day of next month');
    }

    public function setDiaUtilPagamento(int $dia): void
    {
        $this->pagamentoNoDiaUtil = $dia;
    }

    public function getDataPagamento(): DateTime
    {
        try {
            return $this->dataPagamento;
        } catch (\Throwable $th) {
            $inicoMes = (clone $this->inicioProximoMes)->modify('first day of next month');
            $carbon = Carbon::parse($inicoMes);
            $dataPagamento = $carbon->addBusinessDays($this->pagamentoNoDiaUtil);
            $string = $dataPagamento->format('Y-m-d');
            $this->dataProcessamento = new DateTime();
            if ($string >= $this->dataProcessamento->format('Y-m-d')) {
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
            $date = Carbon::getMonthBusinessDays($this->dates->getInicio());
            $this->diasUteis = count($date);
        }
        return $this->diasUteis;
    }
}
