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

use App\Entity\Producao\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use App\Provider\Kimai;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
    /** @var User[] */
    private array $list = [];
    private EntityManagerInterface $entityManagerAkaunting;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
        $this->entityManagerAkaunting = $managerRegistry->getManager('akaunting');
    }

    public function setVisibility(int $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
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
        $list = $this->doRequestKimai('/users', [
            'visible' => $this->visibility,
        ]);
        foreach ($list as $row) {
            try {
                $user = $this->fromArray($row);
                $this->list[] = $user;
            } catch (InvalidArgumentException) {
            } catch (\Throwable $th) {
                $this->logger->alert('Falha ao salvar dados de usuário', [
                    'message' => $th->getMessage(),
                    'data' => $row,
                ]);
            }
        }
        $this->logger->debug('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    public function fromArray(array $array): User
    {
        $array = $this->updateFromUserPreferences($array);
        $array = $this->updateWithAkauntingData($array);
        $array = $this->convertFields($array);
        $entity = $this->entityManager->find(User::class, $array['id']);
        if (!$entity instanceof User) {
            $entity = new User();
        }
        $this->validate($array);
        $entity->fromArray($array);
        return $entity;
    }

    private function validate(array $array): void
    {
        if (empty($array['tax_number'])) {
            $this->logger->alert('Cooperado sem CPF: {cooperado}', [
                'cooperado' => $array['alias'],
            ]);
            throw new InvalidArgumentException('Cooperado sem CPF encontrado');
        }
    }

    private function updateFromUserPreferences(array $item): array
    {
        $detailed = $this->doRequestKimai('/users/' . $item['id']);
        $preferences = array_column($detailed['preferences'], 'value', 'name');
        $item['kimai_username'] = $item['username'];
        if (!$item['alias']) {
            $item['alias'] = $item['username'];
        }
        $item['email'] = $preferences['email'] ?? null;
        if ($preferences['tax_number'] ?? '' !== '') {
            $item['tax_number'] = $preferences['tax_number'];
        }
        $item['dependents'] = (int) ($preferences['dependents'] ?? 0);
        return $item;
    }

    private function updateWithAkauntingData(array $item): array
    {
        if (empty($item['tax_number'])) {
            return $item;
        }
        $qb = $this->entityManagerAkaunting->getConnection()->createQueryBuilder();
        $qb->select('c.*')
            ->from('contacts', 'c')
            ->where($qb->expr()->or(
                $qb->expr()->eq('c.tax_number', $qb->createNamedParameter($item['tax_number'])),
                $qb->expr()->eq('c.email', $qb->createNamedParameter($item['email'])),
            ))
            ->andWhere('c.deleted_at IS NULL')
            ->andWhere($qb->expr()->in('c.type', $qb->createNamedParameter(['vendor', 'employee'], ArrayParameterType::STRING)))
            ->orderBy('c.type');
        $result = $qb->executeQuery();
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

    public function updatePesos(array $pesos): self
    {
        $update = $this->entityManager->getConnection()->createQueryBuilder();
        foreach ($pesos as $cooperado) {
            $update->update('users')
                ->set('peso', $update->createNamedParameter($cooperado['weight']))
                ->where($update->expr()->eq('tax_number', $update->createNamedParameter($cooperado['tax_number'], ParameterType::STRING)))
                ->executeStatement();
        }
        return $this;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->list as $row) {
            $this->saveRow($row);
        }
        return $this;
    }

    public function saveRow(User $user): self
    {
        $em = $this->entityManager;
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
