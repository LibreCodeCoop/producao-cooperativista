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

/**
 * Calculadora de teste: https://www27.receita.fazenda.gov.br/simulador-irpf/
 */
class IRPF
{
    private array $tabelaProgressiva = [
        2023 => [
            [
                'mes_inicio' => 1,
                'mes_fim' => 4,
                'deducao_por_dependente' => 189.59,
                'aliquotas' => [
                    # Janeiro a abril
                    [
                        'min' => 0,
                        'max' => 1993.98,
                        'aliquota' => 0,
                        'deducao' => 0,
                    ],
                    [
                        'min' => 1993.99,
                        'max' => 2826.65,
                        'aliquota' => 0.075,
                        'deducao' => 142.80,
                    ],
                    [
                        'min' => 2826.66,
                        'max' => 3751.05,
                        'aliquota' => 0.15,
                        'deducao' => 354.80,
                    ],
                    [
                        'min' => 3751.06,
                        'max' => 4664.68,
                        'aliquota' => 0.225,
                        'deducao' => 636.13,
                    ],
                    [
                        'min' => 4664.69,
                        'max' => null,
                        'aliquota' => 0.275,
                        'deducao' => 869.36,
                    ],
                ],
            ],
            [
                'mes_inicio' => 5,
                'mes_fim' => null,
                'deducao_por_dependente' => 189.59,
                'aliquotas' => [
                    # Maio a dezembro
                    [
                        'min' => 0,
                        'max' => 2112,
                        'aliquota' => 0,
                        'deducao' => 0,
                    ],
                    [
                        'min' => 2112.01,
                        'max' => 2826.65,
                        'aliquota' => 0.075,
                        'deducao' => 158.40,
                    ],
                    [
                        'min' => 2826.66,
                        'max' => 3751.05,
                        'aliquota' => 0.15,
                        'deducao' => 370.40,
                    ],
                    [
                        'min' => 3751.06,
                        'max' => 4664.68,
                        'aliquota' => 0.225,
                        'deducao' => 651.73,
                    ],
                    [
                        'min' => 4664.69,
                        'max' => null,
                        'aliquota' => 0.275,
                        'deducao' => 884.96,
                    ],
                ]
            ]
        ]
    ];
    private array $tabela;
    private string $tipoDeducao = '';

    public function __construct(
        private int $anoBase,
        private int $mes
    ) {
        $this->tabela = $this->filtraTabelaProgressiva();
    }

    private function filtraTabelaProgressiva(): array
    {
        $tabelasDoAnoBase = $this->tabelaProgressiva[$this->anoBase];
        $aliquotasDoMes = array_filter(
            $tabelasDoAnoBase,
            fn($t) => $this->isOnMonthInterval($t)
        );
        return current($aliquotasDoMes);
    }

    private function isOnMonthInterval(array $row): bool
    {
        if ($this->mes >= $row['mes_inicio']) {
            if ($this->mes <= $row['mes_fim'] || is_null($row['mes_fim'])) {
                return true;
            }
        }
        return false;
    }

    public function getFaixa(float $base): array {
        foreach ($this->tabela['aliquotas'] as $aliquota) {
            if ($base >= $aliquota['min']) {
                if ($base <= $aliquota['max'] || is_null($aliquota['max'])) {
                    return $aliquota;
                }
            }
        }
    }

    public function calculaBase(float $bruto, float $inss, int $dependentes): float
    {
        if ($this->anoBase >= 2023 && $this->mes >= 5) {
            $deducao = $this->calculaDeducaoFavoravel($inss, $dependentes);
        } else {
            $this->tipoDeducao = 'tradicional';
            $deducao = $this->calculaDeducaoTradicional($inss, $dependentes);
        }
        return $bruto - $deducao;
    }

    private function calculaDeducaoFavoravel(float $inss, int $dependentes): float
    {
        $simplificada = $this->calculaDeducaoSimplificada($inss);
        $tradicional = $this->calculaDeducaoTradicional($inss, $dependentes);
        if ($simplificada <= $this->tabela['aliquotas'][0]['max'] * 0.25) {
            $this->tipoDeducao = 'simplificada';
            return $simplificada;
        }
        $this->tipoDeducao = 'tradicional';
        return $tradicional;
    }

    public function getTipoDeducao(): string
    {
        return $this->tipoDeducao;
    }

    private function calculaDeducaoSimplificada(float $inss): float
    {
        $deducaoAliquotaPrimeiraFaixa = $this->tabela['aliquotas'][0]['max'] * 0.25;
        if ($deducaoAliquotaPrimeiraFaixa > $inss) {
            return $deducaoAliquotaPrimeiraFaixa;
        }
        return $inss;
    }

    private function calculaDeducaoTradicional(float $inss, int $dependentes): float
    {
        return $inss + $dependentes * $this->tabela['deducao_por_dependente'];
    }

    public function calcula(float $base, int $dependentes): float
    {
        $faixa = $this->getFaixa($base);
        return $base * $faixa['aliquota'] - ($faixa['deducao']);
    }
}
