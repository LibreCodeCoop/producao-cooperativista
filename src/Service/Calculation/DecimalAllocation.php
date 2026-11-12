<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use Modules\CooperativeProduction\Domain\Calculation\DecimalAllocation as PortableDecimalAllocation;

/**
 * Legacy compatibility facade. Financial rounding has one implementation,
 * shared by the Symfony application and the native Akaunting module.
 */
final class DecimalAllocation
{
    public static function decimal(float|int|string $value): string
    {
        return PortableDecimalAllocation::decimal($value);
    }

    public static function cents(float|int|string $amount): int
    {
        return PortableDecimalAllocation::cents($amount);
    }

    public static function amount(int $cents): float
    {
        return PortableDecimalAllocation::amount($cents);
    }

    /**
     * @param array<int|string, float|int|string> $weights
     * @return array<int|string, float>
     */
    public function allocate(float|int|string $amount, array $weights): array
    {
        return (new PortableDecimalAllocation())->allocate($amount, $weights);
    }
}
