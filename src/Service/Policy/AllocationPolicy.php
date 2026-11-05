<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

interface AllocationPolicy
{
    /** @psalm-suppress PossiblyUnusedMethod Discovered by the plugin registry at runtime. */
    public function id(): string;

    /**
     * @param list<array{customer_reference:string, amount:float}> $receipts Reviewed monetary basis per customer
     * @param list<array{customer_reference:string, member_id:string, units:float}> $measurements
     */
    public function allocate(array $receipts, array $measurements): AllocationResult;
}
