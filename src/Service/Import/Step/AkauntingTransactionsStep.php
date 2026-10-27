<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import\Step;

use App\Service\Import\SourceStep;
use App\Service\Akaunting\Source\Transactions;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Source adapter; extracted dynamically by Symfony's tagged iterator.
 * @psalm-suppress UnusedClass
 */
#[AsTaggedItem(priority: 400)]
final readonly class AkauntingTransactionsStep implements SourceStep
{
    public function __construct(private Transactions $source)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'akaunting.transactions';
    }

    #[\Override]
    public function synchronize(DateTime $competence): void
    {
        $this->source->setDate((clone $competence)->modify('first day of next month'))->saveList();
    }
}
