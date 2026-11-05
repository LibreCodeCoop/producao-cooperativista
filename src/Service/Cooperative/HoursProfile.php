<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

/**
 * Illustrative, review-only profile for other cooperatives using time entries.
 * Its financial distribution policy requires explicitly approved eligible
 * amounts. It intentionally has NO invoice, FRRA or tax publishing rights.
 */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class HoursProfile implements CooperativeProfile
{
    #[\Override]
    public function id(): string
    {
        return 'hours';
    }

    #[\Override]
    public function sources(): array
    {
        return ['kimai.customers', 'kimai.projects', 'kimai.timesheets', 'kimai.users.basic'];
    }

    #[\Override]
    public function allocationPolicyId(): string
    {
        return 'hours-proportional';
    }

    #[\Override]
    public function permitsLibreCodeSettlement(): bool
    {
        return false;
    }
}
