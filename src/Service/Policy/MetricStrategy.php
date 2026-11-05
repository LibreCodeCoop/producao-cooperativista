<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

/** Convert one externally collected work observation into a policy-defined unit. */
interface MetricStrategy
{
    /** @psalm-suppress PossiblyUnusedMethod Discovered by the plugin registry at runtime. */
    public function id(): string;

    /** @param array<string, mixed> $record */
    public function units(array $record): float;
}
