<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Kimai\Source\BasicUsers;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Independent Kimai user adapter for profiles without LibreCode Nextcloud
 * attributes or an existing Akaunting contact mapping.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 100)]
final readonly class KimaiBasicUsersStep implements SourceStep
{
    public function __construct(private BasicUsers $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'kimai.users.basic';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $this->source->saveList();
    }
}
