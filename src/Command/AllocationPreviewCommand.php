<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Command;

use App\Domain\Competencia;
use App\Service\Policy\AllocationPreview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
#[AsCommand(name: 'allocation:preview', description: 'Prévia de rateio por política cooperativa, sem emitir documentos no Akaunting')]
final class AllocationPreviewCommand extends Command
{
    public function __construct(private readonly AllocationPreview $calculator)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('ano-mes', null, InputOption::VALUE_REQUIRED, 'Competência YYYY-MM')
            ->addOption('receipts-file', null, InputOption::VALUE_REQUIRED, 'JSON local com valores elegíveis aprovados por cliente')
            ->addOption('metric', null, InputOption::VALUE_REQUIRED, 'Métrica: elapsed-hours ou work-entries', 'elapsed-hours');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $period = Competencia::fromString((string) $input->getOption('ano-mes'));
            $path = trim((string) $input->getOption('receipts-file'));
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException('Arquivo local com bases elegíveis aprovadas indisponível.');
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Não foi possível ler o arquivo de bases aprovadas.');
            }
            $result = $this->calculator->calculate($period, $contents, (string) $input->getOption('metric'));
            $allocation = $result['allocation'];
            $output->writeln(json_encode([
                'profile' => $result['profile'],
                'policy' => $result['policy'],
                'metric' => $result['metric'],
                'competence' => $result['competence'],
                'receipt_sha256' => $result['receipt_sha256'],
                'eligible_total' => $allocation->eligibleTotal,
                'allocated_total' => array_sum($allocation->amounts),
                'amounts' => $allocation->amounts,
                'by_customer' => $allocation->byCustomer,
                'publication' => 'disabled',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT |
                JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>Prévia não concluída: ' .
                \Symfony\Component\Console\Formatter\OutputFormatter::escape($error->getMessage()) .
                '</error>');
            return Command::FAILURE;
        }
    }
}
