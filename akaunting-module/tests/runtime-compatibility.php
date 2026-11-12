<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Dependency-free PHP 8.3 runtime contract validation.
 * The Symfony legacy uses PHP 8.5 and PHPUnit 13, so testing this separately
 * is required to prove the module is loadable in the Akaunting 3 runtime.
 */
spl_autoload_register(static function (string $name): void {
    $prefix = 'Modules\\CooperativeProduction\\';
    if (!str_starts_with($name, $prefix)) {
        return;
    }

    require dirname(__DIR__) . '/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
});

use Modules\CooperativeProduction\Domain\Calculation\AdministrativeRate;
use Modules\CooperativeProduction\Domain\Calculation\CustomerCostAllocator;
use Modules\CooperativeProduction\Domain\Calculation\DecimalAllocation;
use Modules\CooperativeProduction\Domain\Calculation\ProductionDistributionPlanner;
use Modules\CooperativeProduction\Domain\Calculation\WeightedAllocator;

function toCents(array $amounts): array
{
    return array_map(DecimalAllocation::cents(...), $amounts);
}

function canonicalize(array $array): array
{
    foreach ($array as &$value) {
        if (is_array($value)) {
            $value = canonicalize($value);
        }
    }
    unset($value);

    if (!array_is_list($array)) {
        ksort($array, SORT_STRING);
    }

    return $array;
}

function executeCase(array $scenario): array
{
    $input = $scenario['input'];

    switch ($scenario['kind']) {
        case 'distribution':
            $plan = (new ProductionDistributionPlanner(new WeightedAllocator()))->plan(
                $input['incomes'],
                $input['work'],
            );

            return [
                'amounts_cents' => toCents($plan->amounts),
                'fixed_eligible_cents' => DecimalAllocation::cents($plan->fixedEligible),
                'variable_eligible_cents' => DecimalAllocation::cents($plan->variableEligible),
                'variable_allocated_cents' => DecimalAllocation::cents($plan->variableAllocated),
            ];
        case 'admin-rate':
            $result = (new AdministrativeRate())->evaluate(
                $input['eligible_base'],
                $input['expenses'],
                $input['reference_percent'],
            );

            return [
                'minimum_cents' => DecimalAllocation::cents($result['minimum']),
                'maximum_cents' => DecimalAllocation::cents($result['maximum']),
                'safety_cents' => DecimalAllocation::cents($result['safety']),
                'rate_cents' => DecimalAllocation::cents($result['rate']),
                'effective_percent_basis_points' => DecimalAllocation::cents($result['effective_percent']),
            ];
        case 'customer-cost':
            return ['amounts_cents' => toCents(
                (new CustomerCostAllocator())->allocate($input['invoices'], $input['costs']),
            )];
        case 'unmatched-costs':
            return ['amounts_cents' => toCents(
                (new CustomerCostAllocator())->unmatchedCosts($input['invoices'], $input['costs']),
            )];
        case 'round-cents':
            return ['cents' => DecimalAllocation::cents($input['amount'])];
        case 'allocate-cents':
            return ['amounts_cents' => toCents(
                (new DecimalAllocation())->allocate($input['amount'], $input['weights']),
            )];
    }

    throw new LogicException('Unknown fixture kind.');
}

$manifest = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/tests/php/Fixtures/portability/calculation-v1.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

if (($manifest['schema_version'] ?? null) !== 1 || !is_array($manifest['cases'] ?? null)) {
    throw new RuntimeException('Unexpected golden-fixture schema.');
}

$passed = 0;
$failed = [];
foreach ($manifest['cases'] as $scenario) {
    try {
        $actual = executeCase($scenario);
        if (isset($scenario['error'])) {
            throw new RuntimeException('Expected a rejected calculation.');
        }
        if (canonicalize($actual) !== canonicalize($scenario['expected'])) {
            throw new RuntimeException('Financial result differs from the golden baseline.');
        }
    } catch (Throwable $error) {
        $expected = $scenario['error'] ?? null;
        if ($expected === null || !is_a($error, $expected['class'])
            || !str_contains($error->getMessage(), $expected['contains'])) {
            $failed[] = $scenario['id'] . ': ' . get_class($error) . ': ' . $error->getMessage();
            continue;
        }
    }
    $passed++;
}

foreach ($failed as $failure) {
    fwrite(STDERR, $failure . PHP_EOL);
}
printf(
    "PHP %s: %d/%d native kernel cases passed\n",
    PHP_VERSION,
    $passed,
    count($manifest['cases']),
);
exit($failed === [] ? 0 : 1);
