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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use KimaiClient\DB\Database;
use KimaiClient\Service\Source\Provider\Kimai;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;

class Customers
{
    use Kimai;
    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(): void
    {
        $this->logger->debug('Baixando dados de customers');
        $list = $this->getFromApi();
        $this->saveToDatabase($list);
    }

    public function getFromApi(): array
    {
        $list = $this->doRequestKimai('/api/customers');
        $list = $this->getExtraFields($list);
        return $list;
    }

    public function saveToDatabase(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('customers')
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
                $update->update('customers')
                    ->set('name', $update->createNamedParameter($row['name']))
                    ->set('number', $update->createNamedParameter($row['number']))
                    ->set('comment', $update->createNamedParameter($row['comment']))
                    ->set('visible', $update->createNamedParameter($row['visible'], ParameterType::INTEGER))
                    ->set('billable', $update->createNamedParameter($row['billable'], ParameterType::INTEGER))
                    ->set('currency', $update->createNamedParameter($row['currency']))
                    ->set('color', $update->createNamedParameter($row['color']))
                    ->set('vat_id', $update->createNamedParameter($row['vat_id']))
                    ->set('time_budget', $update->createNamedParameter($row['time_budget']))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                    ->executeStatement();
                continue;
            }
            $insert->insert('customers')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'name' => $insert->createNamedParameter($row['name']),
                    'number' => $insert->createNamedParameter($row['number']),
                    'comment' => $insert->createNamedParameter($row['comment']),
                    'visible' => $insert->createNamedParameter($row['visible'], ParameterType::INTEGER),
                    'billable' => $insert->createNamedParameter($row['billable'], ParameterType::INTEGER),
                    'currency' => $insert->createNamedParameter($row['currency']),
                    'color' => $insert->createNamedParameter($row['color']),
                    'vat_id' => $insert->createNamedParameter($row['vat_id']),
                    'time_budget' => $insert->createNamedParameter($row['time_budget'], ParameterType::INTEGER),
                ])
                ->executeStatement();
        }
    }

    private function getExtraFields(array $list): array
    {
        $client = HttpClient::create();
        foreach ($list as $key => $customer) {
            $this->logger->debug('Dados extras do customer: {name}', ['name' => $customer['name']]);
            $result = $client->request(
                'GET',
                $_ENV['KIMAI_API_BASE_URL'] . '/api/customers/' . $customer['id'],
                [
                    'headers' => [
                        'X-AUTH-USER' => $_ENV['KIMAI_AUTH_USER'],
                        'X-AUTH-TOKEN' => $_ENV['KIMAI_AUTH_TOKEN'],
                    ],
                ]
            );
            $allFields = $result->toArray();
            $this->logger->debug('{json}', ['json' => $allFields]);
            $list[$key]['time_budget'] = $allFields['timeBudget'];
            $list[$key]['vat_id'] = $allFields['vatId'];
        }
        return $list;
    }
}