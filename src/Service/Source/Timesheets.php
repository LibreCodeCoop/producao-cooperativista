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

namespace KimaiClient\Service\Source;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use KimaiClient\DB\Database;
use KimaiClient\Service\Source\Provider\Kimai;
use Psr\Log\LoggerInterface;

class Timesheets
{
    use Kimai;
    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(DateTime $data): void
    {
        $this->logger->debug('Baixando dados de timesheets');
        $list = $this->getFromApi($data);
        $this->saveToDatabase($list);
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
        if ($begin) {
            $query['begin'] = $begin->format('Y-m-d\TH:i:s');
        }
        if ($end) {
            $query['end'] = $end->format('Y-m-d\TH:i:s');
        }
        if ($user) {
            $query['user'] = $user;
        }
        if ($exported) {
            $query['exported'] = $exported === 'all' ? null : $exported;
        }
        $list = $this->doRequestKimai('/api/timesheets', $query);
        return $list;
    }

    public function saveToDatabase(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('timesheet')
            ->where($select->expr()->in('id', ':id'))
            ->setParameter('id', array_column($list, 'id'), Connection::PARAM_INT_ARRAY);
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('timesheet')
                    ->set('activity_id', $update->createNamedParameter($row['activity'], ParameterType::INTEGER))
                    ->set('project_id', $update->createNamedParameter($row['project'], ParameterType::INTEGER))
                    ->set('user_id', $update->createNamedParameter($row['user'], ParameterType::INTEGER))
                    ->set('begin', $update->createNamedParameter($this->convertDate($row['begin']), Types::DATETIME_MUTABLE))
                    ->set('end', $update->createNamedParameter($this->convertDate($row['end']), Types::DATETIME_MUTABLE))
                    ->set('duration', $update->createNamedParameter($row['duration'], ParameterType::INTEGER))
                    ->set('description', $update->createNamedParameter($row['description']))
                    ->set('rate', $update->createNamedParameter($row['rate'], Types::FLOAT))
                    ->set('internalRate', $update->createNamedParameter($row['internalRate'], Types::FLOAT))
                    ->set('exported', $update->createNamedParameter($row['exported'], ParameterType::INTEGER))
                    ->set('billable', $update->createNamedParameter($row['billable'], ParameterType::INTEGER))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                    ->executeStatement();
                continue;
            }
            $insert->insert('timesheet')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'activity_id' => $insert->createNamedParameter($row['activity'], ParameterType::INTEGER),
                    'project_id' => $insert->createNamedParameter($row['project'], ParameterType::INTEGER),
                    'user_id' => $insert->createNamedParameter($row['user'], ParameterType::INTEGER),
                    'begin' => $insert->createNamedParameter($this->convertDate($row['begin']), Types::DATETIME_MUTABLE),
                    'end' => $insert->createNamedParameter($this->convertDate($row['end']), Types::DATETIME_MUTABLE),
                    'duration' => $insert->createNamedParameter($row['duration'], ParameterType::INTEGER),
                    'description' => $insert->createNamedParameter($row['description']),
                    'rate' => $insert->createNamedParameter($row['rate'], Types::FLOAT),
                    'internalRate' => $insert->createNamedParameter($row['internalRate'], Types::FLOAT),
                    'exported' => $insert->createNamedParameter($row['exported'], ParameterType::INTEGER),
                    'billable' => $insert->createNamedParameter($row['billable'], ParameterType::INTEGER),
                ])
                ->executeStatement();
        }
    }

    private function convertDate($date): ?DateTime
    {
        if (!$date) {
            return null;
        }
        $date = preg_replace('/-\d{4}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}