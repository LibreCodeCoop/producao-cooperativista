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

use ProducaoCooperativista\Service\INSS;
use ProducaoCooperativista\Service\IRPF;
use Tests\Php\TestCase;

final class IRPFTest extends TestCase
{
    public function testTabelaInexistente(): void {
        $this->expectException(InvalidArgumentException::class);
        $ano = new DateTime();
        $ano = (int) $ano->format('Y');
        $ano++;
        new IRPF($ano, 1);
    }

    /**
     * @dataProvider providerGetFaixa
     */
    public function testGetFaixa(int $ano, int $mes, float $base, array $faixa): void
    {
        $IRPF = new IRPF($ano, $mes);
        $actual = $IRPF->getFaixa($base);
        $this->assertEquals($actual['aliquota'], $faixa['aliquota'], 'Alíquota incorreta');
        $this->assertEquals($actual['deducao'], $faixa['deducao'], 'Dedução incorreta');
    }

    public static function providerGetFaixa(): array
    {
        return [
            # Primeira tabela do ano
            ## Base negativa
            [
                'ano' => 2023,
                'mes' => 1,
                'base' => -1000,
                'faixa' => [
                    'aliquota' => 0,
                    'deducao' => 0,
                ],
            ],
            # Primeira tabela do ano
            ## Mínima
            [
                'ano' => 2023,
                'mes' => 1,
                'base' => 1000,
                'faixa' => [
                    'aliquota' => 0,
                    'deducao' => 0,
                ],
            ],
            ## Teto de uma alíquota
            [
                'ano' => 2023,
                'mes' => 1,
                'base' => 2826.65,
                'faixa' => [
                    'aliquota' => 0.075,
                    'deducao' => 142.8,
                ],
            ],
            ## Alíquota máxima
            [
                'ano' => 2023,
                'mes' => 1,
                'base' => 4664.69,
                'faixa' => [
                    'aliquota' => 0.275,
                    'deducao' => 869.36,
                ],
            ],
            # Segunda tabela do ano
            ## Mínima
            [
                'ano' => 2023,
                'mes' => 6,
                'base' => 2112,
                'faixa' => [
                    'aliquota' => 0,
                    'deducao' => 0,
                ],
            ],
            ## Teto de uma alíquota
            [
                'ano' => 2023,
                'mes' => 6,
                'base' => 2826.65,
                'faixa' => [
                    'aliquota' => 0.075,
                    'deducao' => 158.40,
                ],
            ],
            ## Alíquota máxima
            [
                'ano' => 2023,
                'mes' => 6,
                'base' => 4664.69,
                'faixa' => [
                    'aliquota' => 0.275,
                    'deducao' => 884.96,
                ],
            ],
        ];
    }

    /**
     * @dataProvider providerCalculaBase
     */
    public function testCalculaBase(int $ano, int $mes, float $bruto, int $dependentes, float $valor, string $tipoDeducao): void
    {
        $inss = (new INSS())->calcula($bruto);
        $IRPF = new IRPF($ano, $mes);
        $atual = $IRPF->calculaBase($bruto, $inss, $dependentes);
        $tipoDeducaoAtual = $IRPF->getTipoDeducao();
        $this->assertEquals($valor, $atual, 'Cálculo base do imposto a pagar incorreto');
        $this->assertEquals($tipoDeducao, $tipoDeducaoAtual);
    }

    public static function providerCalculaBase(): array
    {
        return [
            [2023, 5, 300, 0, 0, 'simplificada'],
            [2023, 5, 1000, 0, 472, 'simplificada'],
            [2023, 5, 3000, 0, 2400, 'tradicional'],
            [2023, 5, 9000, 0, 7582.556, 'tradicional'],
            [2023, 5, 2600, 0, 2072, 'simplificada'],
            [2023, 4, 2600, 0, 2080, 'tradicional'],
        ];
    }

    /**
     * @dataProvider providerCalculaImposto
     */
    public function testCalculaImposto(int $ano, int $mes, float $bruto, int $dependentes, float $valor, string $tipoDeducao): void
    {
        $inss = (new INSS())->calcula($bruto);
        $IRPF = new IRPF($ano, $mes);
        $base = $IRPF->calculaBase($bruto, $inss, $dependentes);
        $atual = $IRPF->calcula($base, $dependentes);
        $tipoDeducaoAtual = $IRPF->getTipoDeducao();
        $this->assertEquals($valor, round($atual, 2), 'Imposto a pagar incorreto');
        $this->assertEquals($tipoDeducao, $tipoDeducaoAtual);
    }

    public static function providerCalculaImposto(): array
    {
        return [
            # Sem dependente
            [2023, 5, 1000, 0, 0, 'simplificada'],
            [2023, 5, 3000, 0, 21.6, 'tradicional'],
            [2023, 5, 9000, 0, 1200.24, 'tradicional'],
            [2023, 5, 2600, 0, 0, 'simplificada'],
            [2023, 4, 2600, 0, 13.2, 'tradicional'],
            # Com 1 dependente
            [2023, 5, 1000, 1, 0, 'simplificada'],
            [2023, 5, 3000, 1, 7.38, 'tradicional'],
            [2023, 5, 9000, 1, 1148.11, 'tradicional'],
            [2023, 5, 2600, 1, 0, 'simplificada'],
            [2023, 4, 2600, 1, 0, 'tradicional'],
            # Com 2 dependentes
            [2023, 5, 1000, 2, 0, 'simplificada'],
            [2023, 5, 3000, 2, 0, 'tradicional'],
            [2023, 5, 9000, 2, 1095.97, 'tradicional'],
            [2023, 5, 2600, 2, 0, 'simplificada'],
            [2023, 4, 2600, 2, 0, 'tradicional'],
        ];
    }
}
