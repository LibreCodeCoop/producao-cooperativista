<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\GetTimesheetsCommand;
use App\Command\MakeProducaoCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Invalid months must be rejected before any external connector is invoked. */
final class CompetenceValidationTest extends KernelTestCase
{
    public function testProductionCommandRejectsOutOfRangeMonth(): void
    {
        self::bootKernel();
        $command = static::getContainer()->get(MakeProducaoCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute(['--ano-mes' => '2025-13']);
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('YYYY-MM', $tester->getDisplay());
    }

    public function testHistoricalReferenceClockRejectsPublicationBeforeCallingIntegrations(): void
    {
        self::bootKernel();
        $command = static::getContainer()->get(MakeProducaoCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--ano-mes' => '2024-02',
            '--as-of' => '2024-03-04',
            '--atualiza-producao' => true,
        ]);
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('exclusivo para prévias', $tester->getDisplay());
    }

    public function testHistoricalReferenceClockRejectsImpossibleCalendarDays(): void
    {
        self::bootKernel();
        $command = static::getContainer()->get(MakeProducaoCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--ano-mes' => '2024-02',
            '--as-of' => '2024-02-31',
            '--baixar-dados' => '0',
        ]);
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('dia válido', $tester->getDisplay());
    }

    public function testKimaiCommandRejectsOutOfRangeMonthBeforeExtraction(): void
    {
        self::bootKernel();
        $command = static::getContainer()->get(GetTimesheetsCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute(['--year-month' => '2024-00']);
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('YYYY-MM', $tester->getDisplay());
    }
}
