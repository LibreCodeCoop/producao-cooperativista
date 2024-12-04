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

use ProducaoCooperativista\Service\Kimai\Source\Users;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'get:users',
    description: 'Get users'
)]
class GetUsersCommand extends BaseCommand
{
    public function __construct(
        private Users $users
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'visible',
                null,
                InputOption::VALUE_REQUIRED,
                'Visibility status to filter users: 1=visible, 2=hidden, 3=all',
                3
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $list = $this->users
            ->setVisibility((int) $input->getOption('visible'))
            ->getList();
        if ($input->getOption('csv')) {
            $output->write($this->toCsv($list));
        }
        if ($input->getOption('database')) {
            $this->users->saveList($list);
        }
        return Command::SUCCESS;
    }
}
