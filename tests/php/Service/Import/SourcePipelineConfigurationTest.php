<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\SourcePipeline;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Checks the real Symfony connector registry without contacting external APIs. */
final class SourcePipelineConfigurationTest extends KernelTestCase
{
    public function testProductionPipelinePreservesDependencyOrder(): void
    {
        self::bootKernel();
        $pipeline = static::getContainer()->get(SourcePipeline::class);
        self::assertSame([
            'kimai.customers',
            'nfse.documents',
            'kimai.projects',
            'akaunting.documents',
            'kimai.timesheets',
            'akaunting.transactions',
            'akaunting.categories',
            'akaunting.taxes',
            'kimai.users',
        ], $pipeline->selectedStageNames());
        self::assertContains('kimai.users.basic', $pipeline->stageNames());
    }
}
