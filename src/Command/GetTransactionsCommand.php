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

namespace App\Command;

use DateTime;
use App\Service\Akaunting\Source\Transactions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'get:transactions',
    description: 'Get transactions'
)]
class GetTransactionsCommand extends AbstractBaseCommand
{
    public function __construct(
        private Transactions $transactions
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'company',
                null,
                InputOption::VALUE_REQUIRED,
                'Company id',
                getenv('AKAUNTING_COMPANY_ID')
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_OPTIONAL,
                'Category id'
            )
            ->addOption(
                'year-month',
                null,
                InputOption::VALUE_REQUIRED,
                'Year and moth to get data. Format: YYYY-mm'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('year-month')) {
            $output->writeln('<error>--year-month is mandatory</error>');
            return Command::INVALID;
        }
        $date = DateTime::createFromFormat('Y-m', $input->getOption('year-month'));
        $list = $this->transactions
            ->setDate($date)
            ->setCompanyId((int) $input->getOption('company'))
            ->setCategoryId((int) $input->getOption('category'))
            ->getList();
        if ($input->getOption('csv')) {
            $output->write($this->toCsv($list));
        }
        if ($input->getOption('database')) {
            $this->transactions->saveList();
        }
        return Command::SUCCESS;
    }
}
