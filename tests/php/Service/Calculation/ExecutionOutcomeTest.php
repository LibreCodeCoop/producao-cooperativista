<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ExecutionOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class ExecutionOutcomeTest extends TestCase
{
    public function testSuccessfulExecutionEndsWithDone(): void
    {
        $outcome = new ExecutionOutcome(Command::SUCCESS, 'run-ok');

        $this->assertSame('done', $outcome->event());
        $this->assertStringContainsString('run-ok', $outcome->message());
    }

    public function testFailedExecutionNeverSendsDoneAndShowsError(): void
    {
        $outcome = new ExecutionOutcome(Command::FAILURE, 'run-001', 'Falha na etapa "importação": serviço indisponível');

        $this->assertSame('failed', $outcome->event());
        $this->assertStringContainsString('importação', $outcome->message());
        $this->assertStringContainsString('run-001', $outcome->message());
    }

    public function testLongTraceDoesNotFloodBrowserMessage(): void
    {
        $outcome = new ExecutionOutcome(Command::FAILURE, 'run-001', str_repeat('X', 2000));

        $this->assertLessThan(850, mb_strlen($outcome->message()));
    }
}
