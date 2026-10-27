<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

/** Infrastructure port: distinct from the pure calculation and ETL sources. */
interface ProductionPublisher
{
    /** @psalm-suppress PossiblyUnusedMethod Discovered by the plugin registry at runtime. */
    public function profileId(): string;

    /**
     * @param array<string, \App\Service\Cooperado> $members
     * @param list<array<string, mixed>> $work
     */
    public function publish(array $members, array $work): void;
}
