<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ProductionDistributionPlanner;
use App\Service\Calculation\WeightedAllocator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProductionDistributionPlannerTest extends TestCase
{
    private ProductionDistributionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ProductionDistributionPlanner(new WeightedAllocator());
    }

    /**
     * Underworked contracts retain the undistributed amount in the cooperative:
     * only eligible receipts from the competence enter the equation.
     */
    public function testHistoricalLowRevenueUsesActualWeightedHoursNotBankBalance(): void
    {
        $incomes = [
            ['customer_reference' => 'cliente-1', 'base_producao' => 9000, 'percentual_desconto_fixo' => false],
        ];
        $work = [
            $this->work('cliente-1', 'alice', 3600, 2, 18000),
            $this->work('cliente-1', 'bob', 7200, 1, 18000),
        ];
        $result = $this->planner->plan($incomes, $work);
        self::assertEqualsWithDelta(9000.0, $result->variableEligible, 0.00001);
        self::assertEqualsWithDelta(5400.0, $result->variableAllocated, 0.00001);
        self::assertEqualsWithDelta(2700.0, $result->amounts['alice'], 0.00001);
        self::assertEqualsWithDelta(2700.0, $result->amounts['bob'], 0.00001);
        self::assertEqualsWithDelta(3600.0, $result->variableEligible - array_sum($result->amounts), 0.00001);
    }

    public function testHighRevenueAndMultipleClientsAreConservedWithFixedDiscount(): void
    {
        $incomes = [
            ['customer_reference' => 'cliente-1', 'base_producao' => 1000, 'percentual_desconto_fixo' => true],
            ['customer_reference' => 'cliente-2', 'base_producao' => 2000, 'percentual_desconto_fixo' => false],
        ];
        $work = [
            $this->work('cliente-1', 'alice', 1000, 1, 4000),
            $this->work('cliente-1', 'bob', 3000, 1, 4000),
            $this->work('cliente-2', 'alice', 2000, 2, 4000),
            $this->work('cliente-2', 'bob', 2000, 1, 4000),
        ];
        $result = $this->planner->plan($incomes, $work);
        self::assertEqualsWithDelta(3000.0, array_sum($result->amounts), 0.00001);
        self::assertEqualsWithDelta(250 + 2000 * 2 / 3, $result->amounts['alice'], 0.00001);
        self::assertEqualsWithDelta(750 + 2000 / 3, $result->amounts['bob'], 0.00001);
        self::assertSame(['alice' => 4000.0, 'bob' => 2000.0], $result->variableWeights);
    }

    public function testReplayingIdenticalInputAndDifferentOrderingProducesSameValues(): void
    {
        $incomes = [
            ['customer_reference' => 'c', 'base_producao' => 200, 'percentual_desconto_fixo' => true],
            ['customer_reference' => 'c', 'base_producao' => 100, 'percentual_desconto_fixo' => true],
        ];
        $work = [
            $this->work('c', 'alice', 90, 2, 100),
            $this->work('c', 'bob', 10, 1, 100),
        ];
        $first = $this->planner->plan($incomes, $work);
        $second = $this->planner->plan(array_reverse($incomes), array_reverse($work));
        $firstAmounts = $first->amounts;
        $secondAmounts = $second->amounts;
        ksort($firstAmounts);
        ksort($secondAmounts);
        self::assertEqualsWithDelta($firstAmounts['alice'], $secondAmounts['alice'], 0.000001);
        self::assertEqualsWithDelta($firstAmounts['bob'], $secondAmounts['bob'], 0.000001);
        self::assertEqualsWithDelta(300, array_sum($second->amounts), 0.000001);
    }

    public function testNoWorkForPositiveCapitalFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('não há horas ponderadas');
        $this->planner->plan([
            ['customer_reference' => 'cliente-1', 'base_producao' => 100, 'percentual_desconto_fixo' => true],
        ], []);
    }

    public function testMixedDiscountRulesForOneClientMustBeReviewed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fixos e móveis');
        $this->planner->plan([
            ['customer_reference' => 'cliente-1', 'base_producao' => 100, 'percentual_desconto_fixo' => true],
            ['customer_reference' => 'cliente-1', 'base_producao' => 100, 'percentual_desconto_fixo' => false],
        ], [$this->work('cliente-1', 'alice', 100, 1, 100)]);
    }

    public function testZeroEligibilityIsZeroWithOrWithoutHours(): void
    {
        $result = $this->planner->plan([], []);
        self::assertSame([], $result->amounts);
        self::assertSame(0.0, $result->variableEligible);
        self::assertSame(0.0, $result->fixedEligible);
    }

    private function work(string $client, string $member, float $seconds, float $weight, float $contracted): array
    {
        return [
            'customer_reference' => $client,
            'tax_number' => $member,
            'trabalhado' => $seconds,
            'peso' => $weight,
            'total_cliente' => $contracted,
        ];
    }
}
