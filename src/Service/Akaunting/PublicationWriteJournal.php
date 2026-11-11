<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Akaunting;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Append-only history of document-write attempts. Each reservation commits
 * before the HTTP call; a crash leaves a pending entry requiring review.
 */
final readonly class PublicationWriteJournal
{
    /** @psalm-suppress PossiblyUnusedMethod Invoked through Symfony DI and accounting Request. */
    public function __construct(private Connection $main)
    {
    }

    public function supports(string $path, string $method): bool
    {
        $method = strtoupper($method);
        return ($method === 'POST' && $path === '/api/documents')
            || ($method === 'PATCH' && preg_match('#^/api/documents/[1-9][0-9]*$#D', $path) === 1);
    }

    /**
     * @param array<string, mixed> $body
     * @return string Opaque attempt ID
     */
    public function reserve(int $companyId, string $method, string $path, array $body): string
    {
        $documentNumber = $body['document_number'] ?? null;
        $type = $body['type'] ?? null;
        $contactId = $body['contact_id'] ?? null;
        if ($companyId <= 0 || !$this->supports($path, $method)
            || !is_string($documentNumber) || trim($documentNumber) === ''
            || !is_string($type) || !in_array($type, ['bill', 'invoice'], true)
            || !is_numeric($contactId) || (int) $contactId <= 0) {
            throw new RuntimeException(
                'Publicação recusada: empresa, tipo, contato ou número do documento não identificados.'
            );
        }

        $method = strtoupper($method);
        $key = hash('sha256', json_encode(
            [$companyId, $method, $path, $type, $documentNumber],
            JSON_THROW_ON_ERROR
        ));
        $fingerprint = hash('sha256', $documentNumber);
        $payloadHash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        $attempt = bin2hex(random_bytes(16));

        try {
            return $this->main->transactional(static function (Connection $db) use (
                $key,
                $attempt,
                $companyId,
                $method,
                $path,
                $fingerprint,
                $payloadHash
            ): string {
                $latest = $db->fetchAssociative(
                    'SELECT attempt_number, state FROM publication_write_attempts '
                    . 'WHERE operation_key = :key ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE',
                    ['key' => $key]
                );
                if ($latest !== false && in_array($latest['state'], ['pending', 'uncertain'], true)) {
                    throw new RuntimeException(
                        'Publicação bloqueada: já existe uma gravação pendente ou de resultado incerto. '
                        . 'Concilie o documento no Akaunting antes de reexecutar.'
                    );
                }

                $db->insert('publication_write_attempts', [
                    'attempt_id' => $attempt,
                    'operation_key' => $key,
                    'attempt_number' => $latest === false ? 1 : (int) $latest['attempt_number'] + 1,
                    'company_id' => $companyId,
                    'method' => $method,
                    'endpoint' => $path,
                    'document_fingerprint' => $fingerprint,
                    'payload_fingerprint' => $payloadHash,
                    'state' => 'pending',
                    'started_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
                return $attempt;
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new RuntimeException(
                'Publicação concorrente detectada. Concilie a operação anterior antes de tentar novamente.',
                0,
                $error
            );
        }
    }

    /**
     * @param 'confirmed'|'rejected'|'uncertain' $state
     */
    public function finish(
        string $attempt,
        string $state,
        ?int $remoteId = null,
        ?int $httpStatus = null
    ): void {
        if (!in_array($state, ['confirmed', 'rejected', 'uncertain'], true)) {
            throw new RuntimeException('Estado inválido de tentativa de publicação.');
        }
        $updated = $this->main->executeStatement(
            'UPDATE publication_write_attempts SET state = :state, remote_document_id = :remote, '
            . 'http_status = :http_status, completed_at = :completed '
            . 'WHERE attempt_id = :attempt AND state = :pending',
            [
                'state' => $state,
                'remote' => $remoteId,
                'http_status' => $httpStatus,
                'completed' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'attempt' => $attempt,
                'pending' => 'pending',
            ]
        );
        if ($updated !== 1) {
            throw new RuntimeException(
                'Não foi possível finalizar o registro local da operação financeira. Verifique o histórico antes de repetir.'
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function needsReconciliation(int $limit = 100): array
    {
        return $this->main->fetchAllAssociative(
            'SELECT attempt_id, company_id, method, endpoint, state, http_status, '
            . 'started_at, completed_at FROM publication_write_attempts '
            . "WHERE state IN ('pending', 'uncertain') ORDER BY started_at ASC LIMIT "
            . max(1, min($limit, 500))
        );
    }
}
