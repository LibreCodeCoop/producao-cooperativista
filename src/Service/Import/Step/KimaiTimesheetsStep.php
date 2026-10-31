<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Kimai\Source\Timesheets;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 500)]
final readonly class KimaiTimesheetsStep implements SourceStep
{
    public function __construct(private Timesheets $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'kimai.timesheets';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $this->source->updateDatabase($competence);
    }
}
