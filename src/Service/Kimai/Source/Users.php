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
use InvalidArgumentException;
use App\Provider\Kimai;
use App\Service\Nextcloud\ProfileFieldsClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use App\Service\Import\BatchPersistence;

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
    /** @var array<int, true>|null */
    private ?array $requiredUserIds = null;
    private EntityManagerInterface $entityManagerAkaunting;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ProfileFieldsClient $profileFieldsClient,
        private BatchPersistence $batchPersistence,
    ) {
        $this->entityManagerAkaunting = $managerRegistry->getManager('akaunting');
    }

    public function setVisibility(int $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    /**
     * Restrict the monthly snapshot to Kimai users referenced by timesheets.
     * Missing fiscal information must still block import for those users.
     * Standalone, unscoped reads retain their existing behavior.
     *
     * @param list<int> $ids
     */
    public function setRequiredUserIds(array $ids): self
    {
        $this->requiredUserIds = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('A relação de cooperados contém um identificador Kimai inválido.');
            }
            $this->requiredUserIds[$id] = true;
        }
        $this->list = [];
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
        if ($this->requiredUserIds === []) {
            return [];
        }
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->info('Importando usuários com visibilidade = {visibilidade}', [
            'visibilidade' => $this->visibilityDataset[$this->visibility],
        ]);
        $list = $this->doRequestKimai('/users', [
            'visible' => $this->visibility,
        ]);
        if (!array_is_list($list)) {
            throw new \UnexpectedValueException('Kimai não retornou uma lista de usuários.');
        }
        $seen = [];
        foreach ($list as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($this->requiredUserIds !== null && !isset($this->requiredUserIds[$id])) {
                continue;
            }
            if ($this->requiredUserIds !== null && isset($seen[$id])) {
                throw new \DomainException(sprintf('PROFILE_SOURCE: usuário Kimai #%d duplicado na origem.', $id));
            }
            $seen[$id] = true;
            try {
                $user = $this->fromArray($row);
                $this->list[] = $user;
            } catch (InvalidArgumentException $error) {
                if ($this->requiredUserIds !== null) {
                    throw new \DomainException(sprintf(
                        'PROFILE_SOURCE: usuário Kimai #%d participante sem cadastro fiscal válido.',
                        $id,
                    ), 0, $error);
                }
            } catch (\DomainException $error) {
                $this->logger->warning('Importação bloqueada por inconsistência cadastral entre fontes.', [
                    'error_class' => $error::class,
                    'kimai_user_id' => (int) ($row['id'] ?? 0),
                ]);
                $message = mb_strtolower($error->getMessage());
                if (str_contains($message, 'dependentes ausentes')
                    || str_contains($message, 'dependentes inválido')) {
                    // A Kimai ID is safe to show to the authenticated finance
                    // team; never stream names, CPF or remote API responses.
                    throw new \DomainException(sprintf(
                        'PROFILE_DEPENDENTS: usuário Kimai #%d sem quantidade válida de dependentes no Nextcloud Profile Fields.',
                        (int) ($row['id'] ?? 0),
                    ), 0, $error);
                }
                throw new \DomainException(sprintf(
                    'PROFILE_SOURCE: usuário Kimai #%d com cadastro ou vínculo inconsistente entre fontes.',
                    (int) ($row['id'] ?? 0),
                ), 0, $error);
            } catch (\Throwable $error) {
                $this->logger->error('Falha ao importar perfil do cooperado.', [
                    'error_class' => $error::class,
                ]);
                throw $error;
            }
        }
        if ($this->requiredUserIds !== null) {
            $missing = array_diff_key($this->requiredUserIds, $seen);
            if ($missing !== []) {
                throw new \DomainException(sprintf(
                    'PROFILE_SOURCE: usuário Kimai #%d possui apontamentos, mas não foi localizado no cadastro de usuários.',
                    (int) array_key_first($missing),
                ));
            }
        }
        $this->logger->debug('Usuários Kimai consultados.', ['count' => count($list)]);
        return $list;
    }

    public function fromArray(array $array): User
    {
        $array = $this->updateFromKimaiUser($array);
        // Match by the exact Kimai ID stored by an administrator in Nextcloud.
        $array = $this->profileFieldsClient->enrichUser(
            $array,
            $this->requiredUserIds !== null,
        );
        // Dependents must come from Nextcloud Profile Fields; missing means invalid.
        $this->validate($array);
        $array = $this->updateWithAkauntingData($array);
        $array = $this->convertFields($array);
        $entity = $this->entityManager->find(User::class, $array['id']);
        if (!$entity instanceof User) {
            $entity = new User();
        }
        $entity->fromArray($array);
        return $entity;
    }

    private function validate(array $array): void
    {
        if (empty($array['tax_number'])) {
            // Only Nextcloud supplies the fiscal reference. Unlinked
            // accounts have no financial identity to import.
            throw new InvalidArgumentException('Cooperado sem CPF encontrado');
        }
        if (!isset($array['dependents']) || !is_int($array['dependents'])
            || $array['dependents'] < 0) {
            throw new \DomainException(
                'Dependentes ausentes ou inválidos no Nextcloud Profile Fields. '
                . 'Informe explicitamente zero quando não houver dependentes e reimporte.'
            );
        }
    }

    private function updateFromKimaiUser(array $item): array
    {
        $detailed = $this->doRequestKimai('/users/' . $item['id']);
        $item['kimai_username'] = $item['username'];
        if (!$item['alias']) {
            $item['alias'] = $item['username'];
        }
        // Kimai exposes email natively; no custom preference is needed.
        $item['email'] = is_string($detailed['email'] ?? null) ? $detailed['email'] : null;
        unset($item['dependents'], $item['tax_number'], $item['akaunting_contact_id']);
        return $item;
    }

    private function updateWithAkauntingData(array $item): array
    {
        $linkedId = (int) ($item['akaunting_contact_id'] ?? 0);
        if ($linkedId <= 0) {
            if ($this->requiredUserIds !== null) {
                throw new \DomainException(
                    'PROFILE_SOURCE: contato financeiro não vinculado no Nextcloud Profile Fields. '
                    . 'Solicite ao administrador o akaunting_contact_id antes de importar.'
                );
            }
            return $item;
        }
        $company = (int) getenv('AKAUNTING_COMPANY_ID');
        if ($company <= 0) {
            throw new \DomainException('Empresa do Akaunting não configurada para conciliar cooperados.');
        }
        $contact = $this->entityManagerAkaunting->getConnection()->fetchAssociative(
            "SELECT id, tax_number FROM contacts
             WHERE id = :id AND company_id = :company
               AND type IN ('vendor', 'employee') AND deleted_at IS NULL",
            ['id' => $linkedId, 'company' => $company],
        );
        if (!is_array($contact) || (int) $contact['id'] !== $linkedId) {
            throw new \DomainException('Contato financeiro vinculado inexistente ou de outra empresa no Akaunting.');
        }
        if (trim((string) $contact['tax_number']) !== (string) ($item['tax_number'] ?? '')) {
            throw new \DomainException('CPF/CNPJ diverge entre o Profile Fields e o contato financeiro selecionado.');
        }
        return $item;
    }

    public function saveList(): self
    {
        $this->getList();
        $this->batchPersistence->save($this->entityManager, $this->list);
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
        $row['akaunting_contact_id'] ??= null;
        $row['metadata'] = $row;
        return $row;
    }
}
