<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

/**
 * The computed amounts are read-only decisions, not Akaunting write commands.
 * Surpluses/FRRA/tax deductions are applied separately by their existing rules.
 */
/** @psalm-suppress PossiblyUnusedProperty Metadata read by audit consumers and tests. */
final readonly class ProductionDistributionPlan
{
    /**
     * @param array<string, float> $amounts Amounts to credit per cooperative member
     * @param array<string, float> $variableWeights Weighted seconds for surplus allocation
     */
    public function __construct(
        public array $amounts,
        public array $variableWeights,
        /** @psalm-suppress PossiblyUnusedProperty Audit-only metadata */
        public float $fixedEligible,
        /** @psalm-suppress PossiblyUnusedProperty Audit-only metadata */
        public float $variableEligible,
        /** @psalm-suppress PossiblyUnusedProperty Audit-only metadata */
        public float $variableAllocated,
    ) {
    }
}
