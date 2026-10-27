<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Kimai\Source\Users;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 100)]
final readonly class KimaiUsersStep implements SourceStep
{
    public function __construct(private Users $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'kimai.users';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $this->source->saveList();
    }
}
