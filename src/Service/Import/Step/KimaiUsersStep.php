<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Kimai\Source\Users;
use App\Service\Kimai\Source\Timesheets;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
// Validate cooperado profiles before the ETL writes other sources.
#[AsTaggedItem(priority: 1000)]
final readonly class KimaiUsersStep implements SourceStep
{
    public function __construct(private Users $source, private Timesheets $timesheets)
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
        // Only cooperados with recorded work in this competence need
        // fiscal parameters to calculate it. An unrelated Kimai account
        // must not block the monthly closing or acquire assumed dependents.
        $ids = [];
        foreach ($this->timesheets->getFromApi($competence) as $row) {
            $id = $row['user'] ?? null;
            if (!is_int($id) || $id <= 0) {
                throw new \DomainException('PROFILE_SOURCE: apontamento Kimai sem identificador de usuário válido.');
            }
            $ids[$id] = $id;
        }
        $this->source->setRequiredUserIds(array_values($ids))->saveList();
    }
}
