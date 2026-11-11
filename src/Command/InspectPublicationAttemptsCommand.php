<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\Akaunting\PublicationWriteJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass Registered as Symfony console command. */
#[AsCommand(name: 'publication:pending', description: 'Lista gravações de documentos sem resultado confirmado no Akaunting')]
final class InspectPublicationAttemptsCommand extends Command
{
    public function __construct(private readonly PublicationWriteJournal $journal)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Quantidade máxima de tentativas não conciliadas (1 a 500)',
            '100'
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            $output->writeln('<error>Informe --limit entre 1 e 500.</error>');
            return Command::INVALID;
        }

        $entries = $this->journal->needsReconciliation($limit);
        $output->writeln('Tentativas financeiras aguardando conciliação: ' . count($entries));
        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                '%s empresa=%d metodo=%s recurso=%s estado=%s http=%s inicio=%s',
                (string) $entry['attempt_id'],
                (int) $entry['company_id'],
                (string) $entry['method'],
                (string) $entry['endpoint'],
                (string) $entry['state'],
                $entry['http_status'] === null ? '-' : (string) $entry['http_status'],
                (string) $entry['started_at'],
            ));
        }

        if ($entries !== []) {
            $output->writeln(
                '<comment>Não reexecute gravações incertas: confira os documentos no Akaunting antes de qualquer retomada.</comment>'
            );
        }
        return Command::SUCCESS;
    }
}
