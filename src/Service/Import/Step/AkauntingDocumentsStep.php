<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Akaunting\Source\Documents;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 600)]
final readonly class AkauntingDocumentsStep implements SourceStep
{
    public function __construct(private Documents $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'akaunting.documents';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $date = (clone $competence)->modify('first day of next month');
        $this->source->setDate($date)->setType('invoice')->saveList()->setType('bill')->saveList();
    }
}
