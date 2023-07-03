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

class IRPF
{
    private float $deducaoPorDependente = 189.59;
    private array $tabelaProgressiva = [
        [
            'ano_inicio' => 2023,
            'ano_fim' => null,
            'min' => 0,
            'max' => 2112,
            'aliquota' => 0,
            'deducao' => 0,
        ],
        [
            'ano_inicio' => 2023,
            'ano_fim' => null,
            'min' => 2112.01,
            'max' => 2826.65,
            'aliquota' => 0.075,
            'deducao' => 158.40,
        ],
        [
            'ano_inicio' => 2023,
            'ano_fim' => null,
            'min' => 2826.66,
            'max' => 3751.05,
            'aliquota' => 0.15,
            'deducao' => 370.40,
        ],
        [
            'ano_inicio' => 2023,
            'ano_fim' => null,
            'min' => 3751.06,
            'max' => 4664.68,
            'aliquota' => 0.225,
            'deducao' => 651.73,
        ],
        [
            'ano_inicio' => 2023,
            'ano_fim' => null,
            'min' => 4664.69,
            'max' => null,
            'aliquota' => 0.275,
            'deducao' => 884.96,
        ],
    ];
    private array $tabela;

    public function __construct(private int $anoBase)
    {
        $this->tabela = $this->filtraTabelaProgressiva();
    }

    private function filtraTabelaProgressiva(): array
    {
        return array_filter(
            $this->tabelaProgressiva,
            fn ($r) => $this->isOnBaseYearInterval($r)
        );
    }

    private function isOnBaseYearInterval(array $row): bool
    {
        if ($this->anoBase >= $row['ano_inicio']) {
            if ($this->anoBase <= $row['ano_fim'] || is_null($row['ano_fim'])) {
                return true;
            }
        }
        return false;
    }

    public function calcula(float $base, float $dependentes): float
    {
        foreach ($this->tabela as $faixa) {
            if ($base >= $faixa['min']) {
                if ($base <= $faixa['max'] || is_null($faixa['max'])) {
                    return $base * $faixa['aliquota'] - ($faixa['deducao'] * $dependentes);
                }
            }
        }
        return 0;
    }

    public function calculaBase(float $baseInss, float $inss, float $dependentes): float
    {
        $base = $baseInss - $inss - ($this->deducaoPorDependente * $dependentes);
        if ($base < 0) {
            return 0;
        }
        return $base;
    }
}
