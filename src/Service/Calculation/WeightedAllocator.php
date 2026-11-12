<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Modules\CooperativeProduction\Domain\Calculation\WeightedAllocator as PortableWeightedAllocator;

/** Compatibility facade for the framework-independent economic kernel. */
final class WeightedAllocator
{
    public function native(): PortableWeightedAllocator
    {
        return new PortableWeightedAllocator();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float>
     */
    public function weightsFromRows(array $rows, string $context): array
    {
        return $this->native()->weightsFromRows($rows, $context);
    }

    /**
     * @param array<string, float|int> $weights
     * @return array<string, float>
     */
    public function allocate(float $eligibleAmount, array $weights, string $context): array
    {
        return $this->native()->allocate($eligibleAmount, $weights, $context);
    }
}
