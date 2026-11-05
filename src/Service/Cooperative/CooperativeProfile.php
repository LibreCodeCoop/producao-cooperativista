<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

/**
 * A deployment profile, not a tenant. Different cooperatives must have
 * separate installations/databases until authenticated tenant isolation exists.
 */
interface CooperativeProfile
{
    public function id(): string;

    /** @return non-empty-list<string> */
    public function sources(): array;

    public function allocationPolicyId(): string;

    /** A production settlement policy must be explicitly reviewed and tested. */
    public function permitsLibreCodeSettlement(): bool;
}
