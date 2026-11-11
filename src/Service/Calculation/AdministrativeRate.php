<?php

declare(strict_types=1);

namespace App\Service\Calculation;

/**
 * LibreCode operating reserve rule traced to the 2022 financial spreadsheet.
 * The 25% reference is NOT a hard ceiling on the effective admin rate:
 * covering the cooperative's internal costs always takes precedence.
 *
 * This pure calculation does not round or allocate payable amounts. Those
 * decisions belong to an approved monetary rounding policy.
 */
final class AdministrativeRate
{
    public function evaluate(float $eligibleBase, float $expenses, int $referencePercent): array
    {
        if (!is_finite($eligibleBase) || !is_finite($expenses) || $expenses < 0
            || $referencePercent < 0 || $referencePercent > 100) {
            throw new \InvalidArgumentException('Parâmetros inválidos na taxa administrativa.');
        }
        if ($expenses > 0 && $eligibleBase <= 0) {
            throw new \DomainException('Base móvel insuficiente para cobrir dispêndios internos.');
        }
        $minimum = DecimalAllocation::decimal($expenses);
        $maximum = bcmul($minimum, '2', 12);
        $base = DecimalAllocation::decimal($eligibleBase);
        $safety = bcdiv(bcmul($base, (string) $referencePercent, 12), '100', 12);
        $selected = bccomp($minimum, $safety, 12) >= 0
            ? $minimum
            : (bccomp($maximum, $safety, 12) >= 0 ? $safety : $maximum);
        $rateCents = DecimalAllocation::cents($selected);
        $rate = DecimalAllocation::amount($rateCents);
        return [
            'minimum' => DecimalAllocation::amount(DecimalAllocation::cents($minimum)),
            'maximum' => DecimalAllocation::amount(DecimalAllocation::cents($maximum)),
            'safety' => DecimalAllocation::amount(DecimalAllocation::cents($safety)),
            'rate' => $rate,
            'effective_percent' => $eligibleBase > 0
                ? (float) bcdiv(bcmul((string) $rate, '100', 12), $base, 12)
                : 0.0,
        ];
    }
}
