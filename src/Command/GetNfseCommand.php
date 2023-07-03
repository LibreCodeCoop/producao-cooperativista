<?php
/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace ProducaoCooperativista\Command;

use DateTime;
use ProducaoCooperativista\Service\Nfse;
use ProducaoCooperativista\Service\Source\Nfse as SourceNfse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'get:nfse',
    description: 'Get NFSe'
)]
class GetNfseCommand extends BaseCommand
{
    public function __construct(
        private SourceNfse $nfse
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'ano-mes',
                null,
                InputOption::VALUE_REQUIRED,
                'Ano e mês para pegar dados da prefeitura, formato: YYYY-mm'
            )
            ->addOption(
                'login',
                null,
                InputOption::VALUE_REQUIRED,
                'Login da Prefeitura, CNPJ ou CPF',
                $_ENV['PREFEITURA_LOGIN'] ?? null
            )
            ->addOption(
                'senha',
                null,
                InputOption::VALUE_REQUIRED,
                'Senha da Prefeitura',
                $_ENV['PREFEITURA_SENHA'] ?? null
            )
            ->addOption(
                'prefeitura',
                null,
                InputOption::VALUE_REQUIRED,
                'Prefeitura a importar',
                $_ENV['PREFEITURA'] ?? null
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = DateTime::createFromFormat('Y-m', $input->getOption('ano-mes'));
        $list = $this->nfse->getFromApi(
            $data,
            $input->getOption('login'),
            $input->getOption('senha'),
            $input->getOption('prefeitura')
        );
        if ($input->getOption('csv')) {
            $output->write($this->toCsv($list));
        }

        if ($input->getOption('database')) {
            $this->nfse->saveList($list);
        }
        return Command::SUCCESS;
    }
}
