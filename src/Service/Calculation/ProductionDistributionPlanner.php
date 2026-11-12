<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Modules\CooperativeProduction\Domain\Calculation\ProductionDistributionPlanner as PortableProductionDistributionPlanner;

/**
 * Symfony compatibility adapter. The native kernel is authoritative, but
 * existing consumers still receive the legacy result value object.
 */
final readonly class ProductionDistributionPlanner
{
    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(private WeightedAllocator $allocator)
    {
    }

    /**
     * @param array<int|string, array<string, mixed>> $incomes
     * @param list<array<string, mixed>> $work
     */
    public function plan(array $incomes, array $work): ProductionDistributionPlan
    {
        $native = new PortableProductionDistributionPlanner($this->allocator->native()))->plan($incomes, $work);
        return new ProductionDistributionPlan(
            $native->amounts,
            $native->variableWeights,
            $native->fixedEligible,
            $native->variableEligible,
            $native->variableAllocated,
        );
    }
}
