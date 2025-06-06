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

namespace App\Service\Kimai\Source;

use DateTime;
use App\Entity\Producao\Timesheet;
use App\Provider\Kimai;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class Timesheets
{
    use Kimai;
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function updateDatabase(DateTime $data): void
    {
        $this->logger->info('Baixando dados de timesheets');
        $list = $this->getFromApi($data);
        $this->saveList($list);
        $this->logger->info('Dados de timesheet salvos com sucesso. Total: {total}', [
            'total' => count($list),
        ]);
    }

    public function getFromApi(DateTime $date, $user = 'all', $exported = 'all'): array
    {
        $begin = $date
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $end = clone $begin;
        $end = $end->modify('last day of this month')
            ->setTime(23, 59, 59);

        $query = [
            'order' => 'ASC',
        ];
        $query['begin'] = $begin->format('Y-m-d\TH:i:s');
        $query['end'] = $end->format('Y-m-d\TH:i:s');
        if ($user) {
            $query['user'] = $user;
        }
        if ($exported) {
            $query['exported'] = $exported === 'all' ? null : $exported;
        }
        $list = $this->doRequestKimai('/timesheets', $query);
        return $list;
    }

    public function saveList(array $list): void
    {
        foreach ($list as $row) {
            $timesheet = $this->entityManager->getRepository(Timesheet::class)->find($row['id']);
            if (!$timesheet instanceof Timesheet) {
                $timesheet = new Timesheet();
                $timesheet->setId($row['id']);
            }
            $timesheet
                ->setActivityId($row['activity'])
                ->setProjectId($row['project'])
                ->setUserId($row['user'])
                ->setBegin($this->convertDate($row['begin']))
                ->setEnd($this->convertDate($row['end']))
                ->setDuration($row['duration'])
                ->setDescription($row['description'])
                ->setRate($row['rate'])
                ->setInternalRate($row['internalRate'])
                ->setExported($row['exported'])
                ->setBillable($row['billable']);
            $this->entityManager->persist($timesheet);
            $this->entityManager->flush();
        }
    }

    private function convertDate($date): ?DateTime
    {
        if (!$date) {
            return null;
        }
        $date = preg_replace('/[-\+]\d{4}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}
