<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ProductionValueExplanations;
use PHPUnit\Framework\TestCase;

final class ProductionValueExplanationsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function individual(array $overrides = []): array
    {
        return array_replace([
            'tax_number' => '12345678901',
            'base_producao' => 10000.00,
            'auxilio' => 2000.00,
            'frra' => 833.33,
            'bruto' => 5000.00,
            'inss' => 1000.00,
            'base_irpf' => 4000.00,
            'irpf' => 0.00,
            'liquid_discount' => 10.00,
            'adiantamento' => [['amount' => 40], ['amount' => 12.50]],
            'liquido' => 4000.00,
            'dependentes' => 0,
        ], $overrides);
    }

    public function test2026ZeroTaxExplainsTheReductionAndTheApplicableBand(): void
    {
        $notes = new ProductionValueExplanations()->describe($this->individual(), 2026, 8);
        self::assertFalse($notes['irpf']['attention']);
        self::assertSame('Por que o IRPF está zerado?', $notes['irpf']['summary']);
        self::assertStringContainsString('redução adicional', $notes['irpf']['detail']);
        self::assertStringContainsString('22,5%', $notes['irpf']['detail']);
        self::assertStringContainsString('R$ 0,00', $notes['irpf']['detail']);
        self::assertStringContainsString('dedução', $notes['base_irpf']['detail']);
        self::assertFalse($notes['base_irpf']['attention']);
        self::assertFalse($notes['inss']['attention']);
        self::assertStringContainsString('20%', $notes['auxilio']['detail']);
        self::assertStringContainsString('1/12', $notes['frra']['detail']);
        self::assertStringContainsString('R$ 52,50', $notes['adiantamento']['detail']);
        self::assertStringContainsString('Akaunting', $notes['liquid_discount']['detail']);
    }

    public function testProgressiveRateWithout2026ReductionIsExplained(): void
    {
        $notes = new ProductionValueExplanations()->describe(
            $this->individual([
                'bruto' => 7500.00, 'inss' => 1500.00,
                'base_irpf' => 6000.00, 'irpf' => 741.27,
            ]),
            2026,
            10,
        );
        self::assertFalse($notes['irpf']['attention']);
        self::assertStringContainsString('27,5%', $notes['irpf']['detail']);
        self::assertStringContainsString('Não houve redução', $notes['irpf']['detail']);
    }

    public function testZeroReportedButPositiveTaxForecastIsFlaggedNotCalledExempt(): void
    {
        $notes = new ProductionValueExplanations()->describe(
            $this->individual([
                'bruto' => 7500.00, 'inss' => 1500.00,
                'base_irpf' => 6000.00, 'irpf' => 0.00,
            ]),
            2026,
            10,
        );
        self::assertTrue($notes['irpf']['attention']);
        self::assertSame('IRPF diverge do motor tributário', $notes['irpf']['summary']);
        self::assertStringContainsString('R$ 741,27', $notes['irpf']['detail']);
    }

    public function testWrongBaseOrInssIsFlaggedWithoutChangingTheSettlement(): void
    {
        $notes = new ProductionValueExplanations()->describe(
            $this->individual(['base_irpf' => 3000, 'inss' => 0]),
            2026,
            8,
        );
        self::assertTrue($notes['base_irpf']['attention']);
        self::assertTrue($notes['inss']['attention']);
        self::assertStringContainsString('diferente do recálculo', $notes['base_irpf']['detail']);
    }

    public function testPJUsesDifferentRulesAndDoesNotInferPFWithholding(): void
    {
        $notes = new ProductionValueExplanations()->describe(
            $this->individual([
                'tax_number' => '12345678000190',
                'auxilio' => 0.0, 'frra' => 0.0,
                'inss' => 0.0, 'irpf' => 0.0, 'base_irpf' => 0.0,
            ]),
            2026,
            8,
        );
        self::assertSame('IRPF não aplicado à PJ', $notes['irpf']['summary']);
        self::assertSame('Não aplicável à PJ', $notes['auxilio']['summary']);
        self::assertFalse($notes['irpf']['attention']);
    }

    public function testMissingYearOfTheTaxEngineDoesNotBreakTheFinancialReport(): void
    {
        $notes = new ProductionValueExplanations()->describe($this->individual(), 2049, 2);
        self::assertTrue($notes['irpf']['attention']);
        self::assertStringContainsString('explicação indisponível', $notes['irpf']['summary']);
        self::assertTrue($notes['inss']['attention']);
    }
}
