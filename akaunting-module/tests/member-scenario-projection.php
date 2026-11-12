<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$base = dirname(__DIR__) . '/Domain';
foreach ([
    '/Calculation/DecimalAllocation.php',
    '/Calculation/WeightedAllocator.php',
    '/Calculation/ProductionDistributionPlan.php',
    '/Calculation/ProductionDistributionPlanner.php',
    '/Forecast/MemberScenarioProjection.php',
] as $path) {
    require $base . $path;
}

use Modules\CooperativeProduction\Domain\Calculation\ProductionDistributionPlanner;
use Modules\CooperativeProduction\Domain\Calculation\WeightedAllocator;
use Modules\CooperativeProduction\Domain\Forecast\MemberScenarioProjection;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$source = [
    'worked_competence' => '2026-09',
    'synchronized_at' => '2026-11-12T09:00:00Z',
    'valid_until' => '2026-11-14T09:00:00Z',
    'public_revision' => 'synthetic-v1',
    'max_additional_seconds' => 7200,
    'evidence' => [
        'cohort_complete' => true,
        'eligible_receipts_verified' => true,
        'identity_link_verified' => true,
        'conditional_receipts_classified' => true,
    ],
    'incomes' => [[
        'customer_reference' => 'example-customer',
        'base_producao' => '120.00',
        'percentual_desconto_fixo' => true,
    ]],
    'work' => [
        ['tax_number' => 'member-a', 'customer_reference' => 'example-customer', 'trabalhado' => 3600, 'peso' => 1],
        ['tax_number' => 'member-b', 'customer_reference' => 'example-customer', 'trabalhado' => 3600, 'peso' => 1],
    ],
    'conditional_incomes' => [[
        'customer_reference' => 'example-customer',
        'base_producao' => '60.00',
        'percentual_desconto_fixo' => true,
        'classification_verified' => true,
        'is_received' => false,
    ]],
];

$service = new MemberScenarioProjection(new ProductionDistributionPlanner(new WeightedAllocator()));
$at = '2026-11-12T12:00:00Z';
$observed = $service->project('member-a', $source, ['type' => 'observed-only'], $at);
$assert($observed['state'] === 'estimated', 'Observed scenario must remain estimated');
$assert($observed['money']['estimated_production_cents'] === 6000, 'Observed distribution changed');
$assert($observed['money']['estimated_net_cents'] === null, 'Unknown net must remain null');
$assert($observed['synchronized_at'] === $source['synchronized_at'], 'Source timestamp missing');
$assert(!str_contains(json_encode($observed, JSON_THROW_ON_ERROR), 'member-b'), 'Other member ID leaked');
$assert(!array_key_exists('paid_cents', $observed['money']), 'Forecast invented a payment');

$additional = $service->project('member-a', $source, [
    'type' => 'additional-hours', 'additional_seconds' => 3600,
    'customer_reference' => 'example-customer',
], $at);
$assert($additional['money']['estimated_production_cents'] === 8000, 'Additional hours distribution changed');
$assert($additional['state'] === 'estimated', 'Hypothetical hours implied approval');

$conditional = $service->project('member-a', $source, ['type' => 'conditional-receipts'], $at);
$assert($conditional['money']['estimated_production_cents'] === 9000, 'Conditional distribution changed');
$assert($conditional['state'] === 'conditional', 'Unreceived income must be conditional');
$assert(in_array('conditional_receipts_are_unreceived', $conditional['limitations'], true), 'Missing warning');

$incomplete = $source;
$incomplete['evidence']['cohort_complete'] = false;
$assert(
    $service->project('member-a', $incomplete, [], $at)['money']['estimated_production_cents'] === null,
    'Incomplete source produced financial estimate'
);
$assert(
    $service->project('member-a', $source, [], '2026-11-16T00:00:00Z')['state'] === 'unavailable',
    'Stale data was accepted'
);
$assert(
    $service->project('member-unknown', $source, [], $at)['money']['estimated_production_cents'] === null,
    'Unlinked member was assigned zero'
);
$assert($service->project('member-a', $source, [
    'type' => 'additional-hours', 'additional_seconds' => 7201,
    'customer_reference' => 'example-customer',
], $at)['state'] === 'unavailable', 'Excess hypothetical hours were accepted');
$assert($service->project('member-a', $source, [
    'type' => 'additional-hours', 'additional_seconds' => 3600,
    'customer_reference' => 'another-customer',
], $at)['state'] === 'unavailable', 'Unverified project gained production');

$unverified = $source;
$unverified['conditional_incomes'][0]['classification_verified'] = false;
$assert(
    $service->project('member-a', $unverified, ['type' => 'conditional-receipts'], $at)['state'] === 'unavailable',
    'Unverified pending invoice produced a financial estimate'
);
$zero = $source;
$zero['incomes'][0]['base_producao'] = '0.00';
$assert(
    $service->project('member-a', $zero, [], $at)['money']['estimated_production_cents'] === 0,
    'Known zero must not be unavailable'
);

foreach ([
    [[], 'invalid timestamp'],
    [['type' => 'unsupported'], 'unsupported scenario'],
] as [$scenario, $label]) {
    try {
        $service->project('member-a', $source, $scenario, $label === 'invalid timestamp' ? 'tomorrow' : $at);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Expected rejection of ' . $label);
}

echo "PASS: synthetic observed/additional/conditional projections, evidence, privacy, stale and null states\n";
