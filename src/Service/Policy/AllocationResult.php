<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

/** Immutable read-only calculation. No invoice or tax instruction is implied. */
final readonly class AllocationResult
{
    /**
     * @param array<string, float> $amounts Amounts per stable member identifier
     * @param array<string, array<string, float>> $byCustomer Traced allocations
     */
    public function __construct(
        public array $amounts,
        public array $byCustomer,
        public float $eligibleTotal,
    ) {
    }
}
