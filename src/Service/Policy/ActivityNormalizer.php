<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use DomainException;

/**
 * Normalizes external measurements once at the integration boundary, allowing
 * independent work-unit strategies without hardcoding Kimai into policies.
 */
final readonly class ActivityNormalizer
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(private MetricRegistry $metrics)
    {
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return list<array{customer_reference:string, member_id:string, units:float}>
     */
    public function measure(array $activities, string $metricId): array
    {
        $metric = $this->metrics->get($metricId);
        $measurements = [];
        foreach ($activities as $record) {
            $units = $metric->units($record);
            if ($units === 0.0) {
                continue;
            }
            $customer = trim((string) ($record['customer_reference'] ?? ''));
            $member = trim((string) ($record['member_id'] ?? ''));
            if ($customer === '' || $member === '') {
                throw new DomainException('Apontamento com unidades positivas sem cooperado ou cliente identificável.');
            }
            $measurements[] = [
                'customer_reference' => $customer,
                'member_id' => $member,
                'units' => $units,
            ];
        }
        return $measurements;
    }
}
