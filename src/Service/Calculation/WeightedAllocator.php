<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;

/**
 * Pure allocation of an amount derived from eligible activity, not bank balances.
 *
 * The caller is responsible for determining the eligible amount according to the
 * cooperative's approved rules. This class never queries financial accounts.
 */
final class WeightedAllocator
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float> Weighted seconds by cooperado identifier.
     */
    public function weightsFromRows(array $rows, string $context): array
    {
        $weights = [];
        foreach ($rows as $row) {
            $cooperado = trim((string) ($row['tax_number'] ?? ''));
            if ($cooperado === '') {
                throw new DomainException(sprintf('%s: apontamento sem identificação do cooperado.', $context));
            }

            $duration = $row['trabalhado'] ?? null;
            $weight = $row['peso'] ?? 1;
            if (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration < 0) {
                throw new DomainException(sprintf('%s: duração inválida para o cooperado %s.', $context, $cooperado));
            }
            if (!is_numeric($weight) || !is_finite((float) $weight) || (float) $weight <= 0) {
                throw new DomainException(sprintf('%s: peso inválido para o cooperado %s.', $context, $cooperado));
            }

            $weightedWork = (float) $duration * (float) $weight;
            if (!is_finite($weightedWork)) {
                throw new DomainException(sprintf('%s: horas ponderadas excedem o limite numérico.', $context));
            }

            $weights[$cooperado] = ($weights[$cooperado] ?? 0.0) + $weightedWork;
        }

        return $weights;
    }

    /**
     * @param array<string, float|int> $weights Aggregated, nonnegative weights.
     * @return array<string, float> The sum of allocations equals the eligible amount.
     */
    public function allocate(float $eligibleAmount, array $weights, string $context): array
    {
        if (!is_finite($eligibleAmount) || $eligibleAmount < 0) {
            throw new DomainException(sprintf('%s: capital elegível para distribuição inválido.', $context));
        }

        $totalWeight = 0.0;
        $lastPositive = null;
        foreach ($weights as $cooperado => $weight) {
            if (!is_finite((float) $weight) || $weight < 0) {
                throw new DomainException(sprintf('%s: peso acumulado inválido para %s.', $context, $cooperado));
            }
            if ($weight > 0) {
                $lastPositive = $cooperado;
            }
            $totalWeight += $weight;
        }

        if (!is_finite($totalWeight)) {
            throw new DomainException(sprintf('%s: soma dos pesos inválida.', $context));
        }
        if ($eligibleAmount > 0 && $totalWeight <= 0) {
            throw new DomainException(sprintf(
                '%s: existem valores elegíveis, mas não há horas ponderadas para distribuí-los.',
                $context
            ));
        }

        return (new DecimalAllocation())->allocate($eligibleAmount, $weights);
    }
}
