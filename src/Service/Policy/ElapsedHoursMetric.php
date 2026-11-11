<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use DomainException;

/** Positive billable Kimai durations measured in decimal hours. */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class ElapsedHoursMetric implements MetricStrategy
{
    #[\Override]
    public function id(): string
    {
        return 'elapsed-hours';
    }

    #[\Override]
    public function units(array $record): float
    {
        $duration = $record['duration'] ?? null;
        if (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration < 0) {
            throw new DomainException('Apontamento Kimai com duração inválida.');
        }
        return (bool) ($record['billable'] ?? false) ? (float) $duration / 3600 : 0.0;
    }
}
