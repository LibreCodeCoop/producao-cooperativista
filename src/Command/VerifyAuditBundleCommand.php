<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\Audit\InputBundleVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/** @psalm-suppress UnusedClass Registered through Symfony attributes. */
#[AsCommand(name: 'audit:verify', description: 'Verifica integridade do bundle privado de uma competência')]
final class VerifyAuditBundleCommand extends Command
{
    public function __construct(
        private readonly InputBundleVerifier $verifier,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Caminho de um bundle gerado por audit:inputs --save-bundle');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $file = (string) $input->getOption('file');
            $result = $this->verifier->verify($file, $this->kernel->getProjectDir() . '/var/audit');
            $output->writeln(sprintf(
                'Bundle da competência %s íntegro. SHA-256: %s',
                $result['competencia'],
                $result['sha256']
            ));
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>Não foi possível verificar o bundle: ' .
                \Symfony\Component\Console\Formatter\OutputFormatter::escape($error->getMessage()) . '</error>');
            return Command::FAILURE;
        }
    }
}
