<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

use DateTime;

/**
 * Connector contract: extract and stage one source for a selected competence.
 * Implementations must not issue financial documents to downstream systems.
 */
interface SourceStep
{
    public function name(): string;
    public function synchronize(DateTime $competence): void;
}
