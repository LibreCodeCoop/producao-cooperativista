<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Reporting;

use App\Domain\Competencia;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A read-only view of the exact Kimai records staged for monthly calculation.
 * The month is selected by begin time, as in the import and source review.
 */
final readonly class ImportedTimesheetReport
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony DI. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $database,
    ) {
    }

    /** @return list<array<string, int|string|null>> */
    public function rows(Competencia $competence): array
    {
        $raw = $this->database->fetchAllAssociative(
            'SELECT t.id AS apontamento_id, t.user_id AS member_id, '
            . 'u.alias AS nome, t.project_id AS project_id, p.name AS projeto, '
            . 'p.customer_id AS customer_id, c.name AS cliente, '
            . 't.activity_id AS atividade_id, t.begin AS inicio, t.end AS fim, '
            . 't.duration AS duracao_segundos, t.description AS descricao, '
            . 't.billable AS faturavel '
            . 'FROM timesheet t '
            . 'LEFT JOIN users u ON u.id = t.user_id '
            . 'LEFT JOIN projects p ON p.id = t.project_id '
            . 'LEFT JOIN customers c ON c.id = p.customer_id '
            . 'WHERE t.begin >= :start AND t.begin < :end '
            . 'ORDER BY t.begin ASC, t.id ASC',
            [
                'start' => $competence->start()->format('Y-m-d'),
                'end' => $competence->endExclusive()->format('Y-m-d'),
            ],
        );

        $rows = [];
        foreach ($raw as $item) {
            $memberId = (int) $item['member_id'];
            $customerId = (int) ($item['customer_id'] ?? 0);
            $rows[] = [
                'nome' => trim((string) ($item['nome'] ?? '')) ?: 'Kimai #' . $memberId,
                'cliente' => trim((string) ($item['cliente'] ?? '')) ?: 'Cliente não vinculado',
                'projeto' => trim((string) ($item['projeto'] ?? '')) ?: 'Projeto #' . (int) $item['project_id'],
                'atividade_id' => (int) $item['atividade_id'],
                'inicio' => (string) $item['inicio'],
                'fim' => $item['fim'] === null ? '' : (string) $item['fim'],
                'duracao_segundos' => $item['duracao_segundos'] === null ? null : (int) $item['duracao_segundos'],
                'descricao' => (string) ($item['descricao'] ?? ''),
                'faturavel' => (int) $item['faturavel'],
                'apontamento_id' => (int) $item['apontamento_id'],
                'member_id' => $memberId,
                'customer_id' => $customerId,
            ];
        }

        return $rows;
    }
}
