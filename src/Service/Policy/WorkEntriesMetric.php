<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use DomainException;

/**
 * Independent example of another metric: each billable work entry counts as
 * one production unit, regardless of its duration.
 */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class WorkEntriesMetric implements MetricStrategy
{
    #[\Override]
    public function id(): string
    {
        return 'work-entries';
    }

    #[\Override]
    public function units(array $record): float
    {
        $duration = $record['duration'] ?? null;
        if (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration < 0) {
            throw new DomainException('Apontamento Kimai com duração inválida.');
        }
        return (bool) ($record['billable'] ?? false) && (float) $duration > 0 ? 1.0 : 0.0;
    }
}
