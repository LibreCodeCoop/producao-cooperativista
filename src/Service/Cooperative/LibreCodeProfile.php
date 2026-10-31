<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

/** Explicit boundary around LibreCode-specific economics and documents. */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class LibreCodeProfile implements CooperativeProfile
{
    #[\Override]
    public function id(): string
    {
        return 'librecode';
    }

    #[\Override]
    public function sources(): array
    {
        return [
            'kimai.customers',
            'nfse.documents',
            'kimai.projects',
            'akaunting.documents',
            'kimai.timesheets',
            'akaunting.transactions',
            'akaunting.categories',
            'akaunting.taxes',
            'kimai.users',
        ];
    }

    #[\Override]
    public function allocationPolicyId(): string
    {
        return 'librecode';
    }

    #[\Override]
    public function permitsLibreCodeSettlement(): bool
    {
        return true;
    }
}
