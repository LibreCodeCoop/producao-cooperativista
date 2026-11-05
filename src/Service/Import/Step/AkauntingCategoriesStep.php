<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Akaunting\Source\Categories;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 300)]
final readonly class AkauntingCategoriesStep implements SourceStep
{
    public function __construct(private Categories $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'akaunting.categories';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $this->source->saveList();
    }
}
