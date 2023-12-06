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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\DB\Entity\Users as EntityUsers;
use ProducaoCooperativista\Provider\Kimai;
use Psr\Log\LoggerInterface;

class Users
{
    use Kimai;
    private int $visibility = 3;
    private array $visibilityDataset = [
        1 => 'visible',
        2 => 'hidden',
        3 => 'all',
    ];
    /** @var EntityUsers[] */
    private array $list = [];
    private array $spreadsheetData = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {
    }

    public function setVisibility(int $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function updateDatabase(): void
    {
        $list = $this->getList();
        $this->saveList($list);
    }

    /**
     * Get users from Kimai API
     *
     * @param integer $visible 1=visible, 2=hidden, 3=all
     * @return array
     */
    public function getList(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->info('Importando usuários com visibilidade = {visibilidade}', [
            'visibilidade' => $this->visibilityDataset[$this->visibility],
        ]);
        $list = $this->doRequestKimai('/api/users', [
            'visible' => $this->visibility,
        ]);
        foreach ($list as $row) {
            try {
                $user = $this->fromArray($row);
                $this->list[] = $user;
            } catch (\Throwable $th) {
                $this->logger->info('Falha ao salvar dados de usuário', [
                    'message' => $th->getMessage(),
                    'data' => $row,
                ]);
            }
        }
        $this->logger->info('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    public function fromArray(array $array): EntityUsers
    {
        $array = $this->updateFromUserPreferences($array);
        $array = $this->updateWithAkauntingData($array);
        $array = $this->convertFields($array);
        $entity = $this->db->getEntityManager()->find(EntityUsers::class, $array['id']);
        if (!$entity instanceof EntityUsers) {
            $entity = new EntityUsers();
        }
        $entity->fromArray($array);
        return $entity;
    }

    private function updateFromUserPreferences(array $item): array
    {
        $detailed = $this->doRequestKimai('/api/users/' . $item['id']);
        $preferences = array_column($detailed['preferences'], 'value', 'name');
        $item['kimai_username'] = $item['username'];
        if (!$item['alias']) {
            $item['alias'] = $item['username'];
        }
        $item['email'] = $preferences['email'] ?? null;
        if ($preferences['tax_number'] ?? '' !== '') {
            $item['tax_number'] = $preferences['tax_number'];
        }
        $item['dependents'] = (int) $preferences['dependents'] ?? 0;
        return $item;
    }

    private function updateWithAkauntingData(array $item): array
    {
        if (empty($item['tax_number'])) {
            return $item;
        }
        $select = new QueryBuilder($this->db->getConnection(Database::DB_AKAUNTING));
        $select->select('c.*')
            ->from('contacts', 'c')
            ->where($select->expr()->or(
                $select->expr()->eq('tax_number', $select->createNamedParameter($item['tax_number'])),
                $select->expr()->eq('email', $select->createNamedParameter($item['email'])),
            ))
            ->andWhere('deleted_at IS NULL')
            ->andWhere($select->expr()->in('type', $select->createNamedParameter(['vendor', 'employee'], ArrayParameterType::STRING)))
            ->orderBy('c.type');
        $result = $select->executeQuery();
        $row = $result->fetchAssociative();
        if (!$row) {
            return $item;
        }

        if ($item['email'] === $row['email']
            || ($item['tax_number'] === $row['tax_number'])
            || ($item['kimai_username'] === $row['email'])
        ) {
            $item['akaunting_contact_id'] = $row['id'];
        }
        return $item;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->list as $row) {
            $this->saveRow($row);
        }
        return $this;
    }

    public function saveRow(EntityUsers $user): self
    {
        $em = $this->db->getEntityManager();
        $em->persist($user);
        $em->flush();
        return $this;
    }

    private function convertFields(array $row): array
    {
        $row['akaunting_contact_id'] = $row['akaunting_contact_id'] ?? null;
        $row['metadata'] = $row;
        return $row;
    }
}
