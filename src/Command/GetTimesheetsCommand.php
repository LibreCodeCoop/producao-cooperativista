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
use ProducaoCooperativista\Service\Kimai\Source\Timesheets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'get:timesheets',
    description: 'Get timesheets'
)]
class GetTimesheetsCommand extends BaseCommand
{
    public function __construct(
        private Timesheets $timesheets
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User ID to filter timesheets. Pass \'all\' to fetch data for all user',
                'all'
            )
            ->addOption(
                'exported',
                null,
                InputOption::VALUE_REQUIRED,
                'Use this flag if you want to filter for export state. Allowed values: 0=not exported, 1=exported',
                'all'
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
        $list = $this->timesheets->getFromApi(
            $date,
            $input->getOption('user'),
            $input->getOption('exported')
        );
        if ($input->getOption('csv')) {
            $output->write(
                $this->toCsv(
                    $list,
                    ['metaFields', 'tags']
                )
            );
        }
        if ($input->getOption('database')) {
            $this->timesheets->saveList($list);
        }
        return Command::SUCCESS;
    }
}
