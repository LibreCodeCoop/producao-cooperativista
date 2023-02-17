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

class Users
{
    use Kimai;
    private array $visibilidade = [
        1 => 'visible',
        2 => 'hidden',
        3 => 'all',
    ];
    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(): void
    {
        $this->logger->debug('Baixando dados de users');
        $list = $this->getFromApi();
        $this->saveToDatabase($list);
    }

    /**
     * Get users from Kimai API
     *
     * @param integer $visible 1=visible, 2=hidden, 3=all
     * @return array
     */
    public function getFromApi(int $visible = 3): array
    {
        $this->logger->debug('Importando usuários com visibilidade = {visibilidade}', [
            'visibilidade' => $this->visibilidade[$visible],
        ]);
        $list = $this->doRequestKimai('/api/users', [
            'visible' => $visible,
        ]);
        $this->logger->debug('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    public function saveToDatabase(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('users')
            ->where($select->expr()->in('id', ':id'))
            ->setParameter('id', array_column($list, 'id'), Connection::PARAM_STR_ARRAY);
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('users')
                    ->set('alias', $update->createNamedParameter($row['alias']))
                    ->set('title', $update->createNamedParameter($row['title']))
                    ->set('username', $update->createNamedParameter($row['username']))
                    ->set('enabled', $update->createNamedParameter($row['enabled'], ParameterType::INTEGER))
                    ->set('color', $update->createNamedParameter($row['color']))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])))
                    ->executeStatement();
                continue;
            }
            $insert->insert('users')
                ->values([
                    'id' => $insert->createNamedParameter($row['id']),
                    'alias' => $insert->createNamedParameter($row['alias']),
                    'title' => $insert->createNamedParameter($row['title']),
                    'username' => $insert->createNamedParameter($row['username']),
                    'enabled' => $insert->createNamedParameter($row['enabled'], ParameterType::INTEGER),
                    'color' => $insert->createNamedParameter($row['color']),
                ])
                ->executeStatement();
        }
    }
}