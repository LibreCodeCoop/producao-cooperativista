<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use Modules\CooperativeProduction\Domain\Calculation\AdministrativeRate as PortableAdministrativeRate;

/** Keeps the approved administrative-rate policy in a single portable kernel. */
final class AdministrativeRate
{
    public function evaluate(float $eligibleBase, float $expenses, int $referencePercent): array
    {
        return new PortableAdministrativeRate()->evaluate($eligibleBase, $expenses, $referencePercent);
    }
}
