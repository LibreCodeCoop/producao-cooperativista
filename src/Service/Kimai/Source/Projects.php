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

namespace ProducaoCooperativista\Service\Kimai\Source;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Provider\Kimai;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;

class Projects
{
    use Kimai;
    private array $list = [];
    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {
    }

    public function updateDatabase(): void
    {
        $this->logger->info('Baixando dados de projects');
        $this->getFromApi();
        $this->saveList($this->list);
        $this->logger->info('Dados de projetos salvos com sucesso. Total: {total}', [
            'total' => count($this->list),
        ]);
    }

    public function getFromApi(): array
    {
        $this->list = $this->doRequestKimai('/api/projects');
        $this->populateWithExtraFields();
        return $this->list;
    }

    public function saveList(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('projects')
            ->where($select->expr()->in('id', ':id'))
            ->setParameter('id', array_column($list, 'id'), ArrayParameterType::INTEGER);
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('projects')
                    ->set('parent_title', $update->createNamedParameter($row['parentTitle']))
                    ->set('customer_id', $update->createNamedParameter($row['customer'], ParameterType::INTEGER))
                    ->set('name', $update->createNamedParameter($row['name']))
                    ->set('start', $update->createNamedParameter($this->convertDate($row['start']), Types::DATE_MUTABLE))
                    ->set('end', $update->createNamedParameter($this->convertDate($row['end']), Types::DATE_MUTABLE))
                    ->set('comment', $update->createNamedParameter($row['comment']))
                    ->set('visible', $update->createNamedParameter($row['visible'], ParameterType::INTEGER))
                    ->set('billable', $update->createNamedParameter($row['billable'], ParameterType::INTEGER))
                    ->set('color', $update->createNamedParameter($row['color']))
                    ->set('global_activities', $update->createNamedParameter($row['globalActivities'], ParameterType::INTEGER))
                    ->set('time_budget', $update->createNamedParameter($row['time_budget']))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                    ->executeStatement();
                continue;
            }
            $insert->insert('projects')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'parent_title' => $insert->createNamedParameter($row['parentTitle']),
                    'customer_id' => $insert->createNamedParameter($row['customer'], ParameterType::INTEGER),
                    'name' => $insert->createNamedParameter($row['name']),
                    'start' => $insert->createNamedParameter($this->convertDate($row['start']), Types::DATE_MUTABLE),
                    'end' => $insert->createNamedParameter($this->convertDate($row['end']), Types::DATE_MUTABLE),
                    'comment' => $insert->createNamedParameter($row['comment']),
                    'visible' => $insert->createNamedParameter($row['visible'], ParameterType::INTEGER),
                    'billable' => $insert->createNamedParameter($row['billable'], ParameterType::INTEGER),
                    'color' => $insert->createNamedParameter($row['color']),
                    'global_activities' => $insert->createNamedParameter($row['globalActivities'], ParameterType::INTEGER),
                    'time_budget' => $insert->createNamedParameter($row['time_budget'], ParameterType::INTEGER),
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
        $date = DateTime::createFromFormat('Y-m-d', $date);
        return $date;
    }

    private function populateWithExtraFields(): void
    {
        $client = HttpClient::create();
        foreach ($this->list as $key => $project) {
            $this->logger->debug('Dados extras do projeto: {name}', ['name' => $project['name']]);
            $result = $client->request(
                'GET',
                rtrim(getenv('KIMAI_API_BASE_URL'), '/') . '/api/projects/' . $project['id'],
                [
                    'headers' => [
                        'X-AUTH-USER' => getenv('KIMAI_AUTH_USER'),
                        'X-AUTH-TOKEN' => getenv('KIMAI_AUTH_TOKEN'),
                    ],
                ]
            );
            $allFields = $result->toArray();
            $this->logger->debug('{json}', ['json' => $allFields]);
            $this->list[$key]['time_budget'] = $allFields['timeBudget'];
        }
    }
}
