<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MakeProducaoCommand;
use App\Helper\Dates;
use App\Service\Movimentacao;
use App\Service\ProducaoCooperativista;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeProducaoCommandFailureTest extends TestCase
{
    public function testImportFailureIsReportedAndAbortsCommand(): void
    {
        [$production, $movements, $logger] = $this->dependencies();
        $production->expects($this->once())
            ->method('loadFromExternalSources')
            ->willThrowException(new RuntimeException('API de origem indisponível'));
        $production->expects($this->never())->method('getProducaoCooperativista');
        $logger->expects($this->once())->method('error');

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        $status = $tester->execute(['--ano-mes' => '2026-08', '--baixar-dados' => '1']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('importação', $tester->getDisplay());
        $this->assertStringContainsString('API de origem indisponível', $tester->getDisplay());
    }

    public function testCalculationFailureIsReportedAndNeverWritesDocuments(): void
    {
        [$production, $movements, $logger] = $this->dependencies();
        $production->expects($this->once())
            ->method('getProducaoCooperativista')
            ->willThrowException(new RuntimeException('Peso inválido'));
        $production->expects($this->never())->method('updateProducao');
        $logger->expects($this->once())->method('error');

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        $status = $tester->execute([
            '--ano-mes' => '2026-08',
            '--baixar-dados' => '0',
            '--atualiza-producao' => true,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('cálculo', $tester->getDisplay());
        $this->assertStringContainsString('Peso inválido', $tester->getDisplay());
    }

    public function testAkauntingWriteFailureIsReportedWithItsPhase(): void
    {
        [$production, $movements, $logger] = $this->dependencies();
        $production->method('getProducaoCooperativista')->willReturn([]);
        $production->expects($this->once())
            ->method('updateProducao')
            ->willThrowException(new RuntimeException('HTTP 503'));
        $logger->expects($this->once())->method('error');

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        $status = $tester->execute([
            '--ano-mes' => '2026-08',
            '--baixar-dados' => '0',
            '--atualiza-producao' => true,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Akaunting', $tester->getDisplay());
        $this->assertStringContainsString('HTTP 503', $tester->getDisplay());
    }

    public function testInvalidDownloadOptionNeverTriggersAnImport(): void
    {
        [$production, $movements, $logger] = $this->dependencies();
        $production->expects($this->never())->method('loadFromExternalSources');
        $production->expects($this->never())->method('getProducaoCooperativista');
        $logger->expects($this->never())->method('error');

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        $status = $tester->execute([
            '--ano-mes' => '2026-08',
            '--baixar-dados' => 'false',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--baixar-dados aceita apenas 0 ou 1', $tester->getDisplay());
    }

    public function testUnsupportedDatabaseOptionDoesNotPretendToSave(): void
    {
        [$production, $movements, $logger] = $this->dependencies();
        $production->expects($this->never())->method('loadFromExternalSources');
        $production->expects($this->never())->method('getProducaoCooperativista');
        $logger->expects($this->never())->method('error');

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        $status = $tester->execute([
            '--ano-mes' => '2026-08',
            '--baixar-dados' => '0',
            '--database' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--database não é suportado', $tester->getDisplay());
    }

    public function testFebruaryStartsOnFirstDayIndependentlyOfCurrentDay(): void
    {
        // This scenario checks the selected month, not the publication path.
        $production = $this->createStub(ProducaoCooperativista::class);
        $production->dates = $this->createMock(Dates::class);
        $movements = $this->createStub(Movimentacao::class);
        $logger = $this->createStub(LoggerInterface::class);
        $production->dates->expects($this->once())
            ->method('setInicio')
            ->with($this->callback(
                static fn (\DateTime $date): bool => $date->format('Y-m-d') === '2026-02-01'
            ));
        $production->method('getProducaoCooperativista')->willReturn([]);

        $tester = new CommandTester(new MakeProducaoCommand($production, $movements, $logger));
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--ano-mes' => '2026-02',
            '--baixar-dados' => '0',
        ]));
    }

    /**
     * @return array{ProducaoCooperativista, Movimentacao, LoggerInterface}
     */
    private function dependencies(): array
    {
        $production = $this->getMockBuilder(ProducaoCooperativista::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadFromExternalSources', 'getProducaoCooperativista', 'updateProducao'])
            ->getMock();
        $production->dates = $this->createStub(Dates::class);
        $movements = $this->createStub(Movimentacao::class);

        return [$production, $movements, $this->createMock(LoggerInterface::class)];
    }
}
