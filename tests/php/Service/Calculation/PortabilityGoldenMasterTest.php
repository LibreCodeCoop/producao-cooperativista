<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\AdministrativeRate;
use App\Service\Calculation\CustomerCostAllocator;
use App\Service\Calculation\DecimalAllocation;
use App\Service\Calculation\ProductionDistributionPlanner;
use App\Service\Calculation\WeightedAllocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Portable, versioned, synthetic reference cases for a second PHP runtime.
 * No network, ORM, HTTP or financial writes. Not a complete ETL baseline.
 */
final class PortabilityGoldenMasterTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/portability/calculation-v1.json';

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        $manifest = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1 || !is_array($manifest['cases'] ?? null)) {
            throw new \RuntimeException('Unknown golden-master fixture schema.');
        }

        foreach ($manifest['cases'] as $scenario) {
            yield $scenario['id'] => [$scenario];
        }
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('scenarios')]
    public function testLegacyEngineMatchesVersionedGoldenCase(array $scenario): void
    {
        if (isset($scenario['error'])) {
            $this->expectException($scenario['error']['class']);
            $this->expectExceptionMessage($scenario['error']['contains']);
            self::evaluate($scenario);
            return;
        }

        self::assertSame(
            self::canonicalize($scenario['expected']),
            self::canonicalize(self::evaluate($scenario)),
            $scenario['id'],
        );
    }

    public function testDistributionVectorsAreIndependentOfInputOrdering(): void
    {
        foreach (self::scenarios() as [$scenario]) {
            if ($scenario['kind'] !== 'distribution' || isset($scenario['error'])) {
                continue;
            }
            $reversed = $scenario;
            $reversed['input']['incomes'] = array_reverse($scenario['input']['incomes']);
            $reversed['input']['work'] = array_reverse($scenario['input']['work']);
            self::assertSame(
                self::canonicalize(self::evaluate($scenario)),
                self::canonicalize(self::evaluate($reversed)),
                $scenario['id'],
            );
        }
    }

    /** @param array<string, mixed> $scenario
     *  @return array<string, mixed>
     */
    private static function evaluate(array $scenario): array
    {
        $input = $scenario['input'];
        return match ($scenario['kind']) {
            'distribution' => self::distribution($input),
            'admin-rate' => self::administrativeRate($input),
            'customer-cost' => self::customerCosts($input),
            'unmatched-costs' => self::unmatchedCosts($input),
            'round-cents' => ['cents' => DecimalAllocation::cents($input['amount'])],
            'allocate-cents' => ['amounts_cents' => self::toCents(new DecimalAllocation()->allocate($input['amount'], $input['weights']))],
            default => throw new \LogicException('Unsupported economic contract.'),
        };
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private static function distribution(array $input): array
    {
        $plan = new ProductionDistributionPlanner(new WeightedAllocator())->plan($input['incomes'], $input['work']);
        return [
            'amounts_cents' => self::toCents($plan->amounts),
            'fixed_eligible_cents' => DecimalAllocation::cents($plan->fixedEligible),
            'variable_eligible_cents' => DecimalAllocation::cents($plan->variableEligible),
            'variable_allocated_cents' => DecimalAllocation::cents($plan->variableAllocated),
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, int>
     */
    private static function administrativeRate(array $input): array
    {
        $result = new AdministrativeRate()->evaluate($input['eligible_base'], $input['expenses'], $input['reference_percent']);
        return [
            'minimum_cents' => DecimalAllocation::cents($result['minimum']),
            'maximum_cents' => DecimalAllocation::cents($result['maximum']),
            'safety_cents' => DecimalAllocation::cents($result['safety']),
            'rate_cents' => DecimalAllocation::cents($result['rate']),
            'effective_percent_basis_points' => DecimalAllocation::cents($result['effective_percent']),
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private static function customerCosts(array $input): array
    {
        return ['amounts_cents' => self::toCents(new CustomerCostAllocator()->allocate($input['invoices'], $input['costs']))];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private static function unmatchedCosts(array $input): array
    {
        return ['amounts_cents' => self::toCents(new CustomerCostAllocator()->unmatchedCosts($input['invoices'], $input['costs']))];
    }

    /** @param array<int|string, float|int|string> $amounts
     *  @return array<int|string, int>
     */
    private static function toCents(array $amounts): array
    {
        return array_map(DecimalAllocation::cents(...), $amounts);
    }

    /** @param array<mixed> $value
     *  @return array<mixed>
     */
    private static function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonicalize($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
