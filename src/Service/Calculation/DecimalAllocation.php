<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;
use InvalidArgumentException;

/**
 * Cent-exact apportionment at the payable boundary.
 *
 * Intermediate arithmetic uses decimal strings (BCMath). Remaining cents are
 * assigned by largest fractional remainder, with deterministic lexical
 * identifier tie-breaking, independent of database row ordering.
 */
final class DecimalAllocation
{
    public static function decimal(float|int|string $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Valor monetário não finito.');
            }
            // The calculation accepts floats and decimal strings. Normalize
            // floats once before applying decimal monetary operations.
            $value = sprintf('%.12F', $value);
        }
        $value = trim((string) $value);
        if (!preg_match('/^[+-]?(?:[0-9]+)(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Valor decimal inválido.');
        }

        return $value;
    }

    public static function cents(float|int|string $amount): int
    {
        $rounded = bcround(self::decimal($amount), 2, \RoundingMode::HalfAwayFromZero);
        $units = bcmul($rounded, '100', 0);
        if (bccomp($units, (string) PHP_INT_MAX, 0) > 0
            || bccomp($units, (string) PHP_INT_MIN, 0) < 0) {
            throw new DomainException('Valor financeiro excede a capacidade de centavos.');
        }

        return (int) $units;
    }

    public static function amount(int $cents): float
    {
        return (float) bcdiv((string) $cents, '100', 2);
    }

    /**
     * @param array<int|string, float|int|string> $weights
     * @return array<int|string, float>
     */
    public function allocate(float|int|string $amount, array $weights): array
    {
        $cents = self::cents($amount);
        if ($cents < 0) {
            throw new DomainException('A distribuição exige um valor não negativo.');
        }
        $sum = '0';
        foreach ($weights as $weight) {
            $decimal = self::decimal($weight);
            if (bccomp($decimal, '0', 12) < 0) {
                throw new DomainException('O peso da distribuição não pode ser negativo.');
            }
            $sum = bcadd($sum, $decimal, 12);
        }
        if ($cents > 0 && bccomp($sum, '0', 12) <= 0) {
            throw new DomainException('Distribuição sem pesos positivos.');
        }
        $base = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $identifier => $weight) {
            $w = self::decimal($weight);
            $portion = $cents && bccomp($w, '0', 12) > 0
                ? bcdiv(bcmul((string) $cents, $w, 16), $sum, 16)
                : '0';
            $floor = (int) bcdiv($portion, '1', 0);
            $assigned += $floor;
            $base[$identifier] = $floor;
            $remainders[$identifier] = bcsub($portion, (string) $floor, 16);
        }

        // Database iteration order must not determine who receives the
        // remaining cent. A stable identifier breaks exact remainder ties.
        uksort($remainders, static function (int|string $a, int|string $b) use ($remainders): int {
            $cmp = bccomp($remainders[$a], $remainders[$b], 16);
            return $cmp === 0 ? strcmp((string) $a, (string) $b) : -$cmp;
        });
        $remaining = $cents - $assigned;
        foreach ($remainders as $identifier => $_) {
            if ($remaining === 0) {
                break;
            }
            $base[$identifier]++;
            $remaining--;
        }
        if ($remaining !== 0) {
            throw new DomainException('Inconsistência no fechamento dos centavos do rateio.');
        }

        return array_map(self::amount(...), $base);
    }
}
