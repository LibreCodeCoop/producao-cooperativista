<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Kimai\Source;

use App\Entity\Producao\User;
use App\Provider\Kimai;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Portable Kimai user import. No LibreCode-only Profile Fields app, no CPF
 * requirement, no contact lookup or accounting writes.
 */
final class BasicUsers
{
    use Kimai;

    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private BatchPersistence $persistence,
        private ExistingEntityIndex $existingIndex,
        private ?HttpClientInterface $http = null,
    ) {
    }

    protected function getKimaiHttpClient(): HttpClientInterface
    {
        return $this->http ?? HttpClient::create();
    }

    public function saveList(): void
    {
        $raw = $this->doRequestKimai('/users', ['visible' => 3]);
        if (!array_is_list($raw)) {
            throw new InvalidArgumentException('Kimai não retornou uma lista de usuários.');
        }
        $ids = [];
        foreach ($raw as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || (int) $row['id'] <= 0) {
                throw new InvalidArgumentException('Kimai retornou um usuário sem identificador válido.');
            }
            $ids[] = (int) $row['id'];
        }

        $indexed = $this->existingIndex->byId($this->entityManager, User::class, $ids);
        $updates = [];
        foreach ($raw as $row) {
            $username = trim((string) ($row['username'] ?? ''));
            $alias = trim((string) ($row['alias'] ?? '')) ?: $username;
            if ($username === '' || $alias === '' || mb_strlen($alias) > 60 || mb_strlen($username) > 180) {
                throw new InvalidArgumentException('Kimai retornou usuário sem nome válido para importação.');
            }
            $id = (int) $row['id'];
            $entity = $indexed[$id] ?? new User();
            $entity->fromArray([
                'id' => $id,
                'alias' => $alias,
                'kimai_username' => $username,
                // Portable source never infers personal identifiers or
                // accounting contacts from LibreCode-specific infrastructure.
                'tax_number' => null,
                'akaunting_contact_id' => null,
                'peso' => null,
                'dependents' => 0,
                'enabled' => (int) ($row['enabled'] ?? 1),
                'metadata' => ['provider' => 'kimai', 'source_id' => $id],
            ]);
            $updates[] = $entity;
        }
        $this->persistence->save($this->entityManager, $updates);
    }
}
