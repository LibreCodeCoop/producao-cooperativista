<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Command;

use App\Domain\Competencia;
use App\Service\Calculation\CalculationExecutionLock;
use App\Service\Import\SourcePipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Stages the sources selected by the deployment profile, without publishing. */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
#[AsCommand(name: 'etl:sync', description: 'Sincroniza apenas as origens habilitadas para o perfil da cooperativa')]
final class SyncSourcesCommand extends Command
{
    public function __construct(
        private readonly SourcePipeline $pipeline,
        private readonly CalculationExecutionLock $lock,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('ano-mes', null, InputOption::VALUE_REQUIRED, 'Competência YYYY-MM');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $competence = Competencia::fromString((string) $input->getOption('ano-mes'));
            $this->lock->acquire();
            $this->pipeline->run($competence->start());
            $output->writeln('Origens sincronizadas para a competência ' . (string) $competence . '.');
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>Falha na importação: ' .
                \Symfony\Component\Console\Formatter\OutputFormatter::escape($error->getMessage()) .
                '</error>');
            return Command::FAILURE;
        } finally {
            $this->lock->release();
        }
    }
}
