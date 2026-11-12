<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Modules\CooperativeProduction\Domain\Calculation\CustomerCostAllocator as PortableCustomerCostAllocator;

/** Legacy facade for per-customer cost allocation and reconciliation checks. */
final class CustomerCostAllocator
{
    /**
     * @param array<int|string, array<string, mixed>> $invoices
     * @param array<string, float> $costs
     * @return array<string, float>
     */
    public function unmatchedCosts(array $invoices, array $costs): array
    {
        return new PortableCustomerCostAllocator()->unmatchedCosts($invoices, $costs);
    }

    /**
     * @param array<int|string, array<string, mixed>> $invoices
     * @param array<string, float> $costs
     * @return array<int|string, float>
     */
    public function allocate(array $invoices, array $costs): array
    {
        return new PortableCustomerCostAllocator()->allocate($invoices, $costs);
    }
}
