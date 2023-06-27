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
use ProducaoCooperativista\Service\Source\Invoices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'get:invoices',
    description: 'Get invoices'
)]
class GetInvoicesCommand extends BaseCommand
{
    public function __construct(
        private Invoices $invoices
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
                $_ENV['AKAUNTING_COMPANY_ID']
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Type of invoice. Allowed values: invoice, bill',
                'invoice'
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
        $list = $this->invoices
            ->setDate($date)
            ->setCompanyId((int) $input->getOption('company'))
            ->setType((string) $input->getOption('type'))
            ->getList();
        if ($input->getOption('csv')) {
            $output->write($this->toCsv($list));
        }
        if ($input->getOption('database')) {
            $this->invoices->saveList();
        }
        return Command::SUCCESS;
    }
}
