<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Impostos\Service\INSS;
use Impostos\Service\IRPF;
use Throwable;

/**
 * Read-only explanations of the actual values; never changes the settlement.
 * The tax comparison uses the same Impostos classes as Values::calculaImpostos().
 */
final class ProductionValueExplanations
{
    /**
     * @param array<string, mixed> $values Values::toArray() from the current computation
     * @return array<string, array{summary: string, detail: string, attention: bool}>
     */
    public function describe(array $values, int $year, int $month): array
    {
        $pf = strlen((string) ($values['tax_number'] ?? '')) === 11;
        $base = (float) ($values['base_producao'] ?? 0);
        $gross = (float) ($values['bruto'] ?? 0);
        $aux = (float) ($values['auxilio'] ?? 0);
        $frra = (float) ($values['frra'] ?? 0);
        $inss = (float) ($values['inss'] ?? 0);
        $irpf = (float) ($values['irpf'] ?? 0);
        $taxBase = (float) ($values['base_irpf'] ?? 0);
        $discount = (float) ($values['liquid_discount'] ?? 0);
        $dependents = (int) ($values['dependentes'] ?? 0);
        $money = self::brl(...);
        $entry = self::entry(...);

        $items = [
            'base_producao' => $entry(
                'Rateio da produção',
                'Valor atribuído ao cooperado a partir das receitas, dispêndios, horas e peso '
                . 'considerados na memória de cálculo da competência. Base apurada: ' . $money($base) . '.',
            ),
            'auxilio' => $pf ? $entry(
                'Auxílio de 20%',
                'Para cooperado pessoa física: 20% da base de produção ('
                . $money($base) . ' × 20%). Auxílio apurado: ' . $money($aux) . '.',
            ) : $entry('Não aplicável à PJ', 'O cálculo de auxílio de 20% é aplicado apenas a cooperados pessoa física.'),
            'frra' => $pf ? $entry(
                'Reserva mensal FRRA',
                'Para cooperado pessoa física: reserva equivalente a 1/12 da base de produção '
                . '(' . $money($base) . ' ÷ 12), arredondada em centavos. '
                . 'Valor apurado: ' . $money($frra)
                . '. O pagamento do FRRA é tratado separadamente, conforme o processamento do período.',
            ) : $entry('Não aplicável à PJ', 'A reserva FRRA mensal é calculada para cooperados pessoa física.'),
            'bruto' => $pf ? $entry(
                'Base menos auxílio e FRRA',
                $money($base) . ' − ' . $money($aux) . ' − ' . $money($frra)
                . ' = bruto de produção ' . $money($gross) . '.',
            ) : $entry('Base de produção', 'Para cooperado PJ, o bruto corresponde à base de produção: '
                . $money($gross) . '.'),
            'liquid_discount' => $entry(
                $discount === 0.0 ? 'Sem desconto líquido identificado' : 'Desconto vindo do Akaunting',
                'Total de descontos líquidos de documentos financeiros classificados no Akaunting '
                . 'e vinculados ao cooperado por identificação nas anotações. '
                . 'Valor encontrado nesta competência: ' . $money($discount)
                . '. Confira os documentos no relatório de entradas e saídas.',
            ),
        ];

        $advances = $values['adiantamento'] ?? [];
        if (is_string($advances)) {
            $advances = json_decode($advances, true) ?: [];
        }
        $advanceTotal = 0.0;
        foreach (is_array($advances) ? $advances : [] as $advance) {
            if (is_array($advance)) {
                $advanceTotal += (float) ($advance['amount'] ?? 0);
            }
        }
        $items['adiantamento'] = $entry(
            $advanceTotal === 0.0 ? 'Sem adiantamentos identificados' : 'Adiantamentos do Akaunting',
            'Total de antecipações financeiras vinculadas ao cooperado na competência: '
            . $money($advanceTotal) . '. São abatidas do líquido, não redistribuídas.',
        );

        if (!$pf) {
            foreach (['inss' => 'INSS', 'base_irpf' => 'Base IRPF', 'irpf' => 'IRPF'] as $key => $label) {
                $items[$key] = $entry(
                    $label . ' não aplicado à PJ',
                    'Este módulo calcula esses tributos para cooperados pessoa física. '
                    . 'Para sócios PJ, a tributação deve ser conferida no documento fiscal correspondente.',
                );
            }
            $items['liquido'] = $entry(
                'Bruto após ajustes',
                'Líquido = bruto − descontos líquidos − adiantamentos. '
                . 'Valor calculado: ' . $money((float) ($values['liquido'] ?? 0)) . '.',
            );

            return $items;
        }

        try {
            $inssExpected = new INSS($year)->calcula($gross);
            $inssDiffers = abs($inssExpected - $inss) > 0.011;
            $items['inss'] = $entry(
                $inssDiffers ? 'INSS precisa de conferência' : 'INSS sobre o bruto',
                'O motor Impostos calcula 20% sobre o bruto de produção, limitado pelo teto anual do INSS '
                . '(' . $year . '). Bruto: ' . $money($gross) . '; INSS apurado: ' . $money($inss)
                . ($inssDiffers ? '; recálculo de referência: ' . $money($inssExpected)
                    . '. Valores diferentes: confira a apuração.' : '.'),
                $inssDiffers,
            );

            $calculator = new IRPF($year, $month);
            $expectedBase = $calculator->calculaBase($gross, $inss, $dependents);
            $deductionType = $calculator->getTipoDeducao();
            $deduction = max(0.0, $gross - $expectedBase);
            $expectedTax = $calculator->calcula($expectedBase, $dependents);
            $baseDiffers = abs($expectedBase - $taxBase) > 0.011;
            $taxDiffers = abs($expectedTax - $irpf) > 0.011;
            $items['base_irpf'] = $entry(
                $baseDiffers ? 'Base do IRPF precisa de conferência' : 'Bruto menos dedução mais favorável',
                'Bruto tributável: ' . $money($gross)
                . '; INSS: ' . $money($inss)
                . '; dependentes: ' . $dependents
                . '; dedução escolhida pelo motor: ' . ($deductionType === 'simplificada' ? 'simplificada' : 'legal')
                . ' (' . $money($deduction) . '). Base resultante: ' . $money($expectedBase) . '.'
                . ($baseDiffers ? ' A base exibida é ' . $money($taxBase)
                    . ', diferente do recálculo. Confira os lançamentos.' : ''),
                $baseDiffers,
            );

            $band = $calculator->getFaixa($expectedBase);
            $rate = (float) ($band['aliquota'] ?? 0);
            $fixedDeduction = (float) ($band['deducao'] ?? 0);
            $beforeReduction = max(0.0, $expectedBase * $rate - $fixedDeduction);
            $reduction = max(0.0, $beforeReduction - $expectedTax);
            $bandDetail = 'Base ' . $money($expectedBase) . ' × '
                . number_format($rate * 100, 1, ',', '.') . '% − parcela a deduzir '
                . $money($fixedDeduction) . ' = ' . $money($beforeReduction)
                . ' antes da redução adicional.';
            if ($beforeReduction <= 0.0) {
                $reason = 'A base ficou na faixa sem imposto após as deduções.';
            } elseif ($reduction >= $beforeReduction - 0.011) {
                $reason = 'A redução adicional da tabela zerou o imposto para esse rendimento tributável.';
            } elseif ($reduction > 0.0) {
                $reason = 'A redução adicional diminuiu o imposto em ' . $money($reduction) . '.';
            } else {
                $reason = 'Não houve redução adicional nessa faixa de rendimento.';
            }

            $items['irpf'] = $entry(
                $taxDiffers ? 'IRPF diverge do motor tributário' :
                    ($irpf <= 0.0 ? 'Por que o IRPF está zerado?' : 'Tabela progressiva e redução'),
                $bandDetail . ' ' . $reason . ' IRPF calculado: ' . $money($expectedTax) . '.'
                . ($taxDiffers ? ' O relatório exibe ' . $money($irpf)
                    . ': há divergência e os dados exigem conferência.' : ''),
                $taxDiffers,
            );
        } catch (Throwable) {
            // The explanation must not turn an otherwise valid report into HTTP 500.
            foreach (['inss' => 'INSS', 'base_irpf' => 'Base IRPF', 'irpf' => 'IRPF'] as $key => $label) {
                $items[$key] = $entry(
                    $label . ' — explicação indisponível',
                    'A tabela tributária do período não pôde ser consultada. '
                    . 'Confira a competência e o motor Impostos antes de publicar.',
                    true,
                );
            }
        }

        $items['liquido'] = $entry(
            'Composição do líquido',
            'Líquido = bruto − descontos líquidos − adiantamentos − INSS − IRPF + auxílio. '
            . 'Valor apurado: ' . $money((float) ($values['liquido'] ?? 0)) . '.',
        );
        return $items;
    }

    private static function brl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /** @return array{summary: string, detail: string, attention: bool} */
    private static function entry(string $summary, string $detail, bool $attention = false): array
    {
        return ['summary' => $summary, 'detail' => $detail, 'attention' => $attention];
    }
}
