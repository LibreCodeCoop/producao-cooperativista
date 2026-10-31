<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Kimai\Source;

use App\Domain\Competencia;
use Doctrine\DBAL\Connection;

/**
 * Reads Kimai-imported observations without applying any cooperative's
 * distribution, withholding, FRRA or customer billing policy.
 *
 * Member identifiers are Kimai source IDs, never inferred CPF/CNPJ.
 */
final readonly class WorkMetricsReader
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(private Connection $staging)
    {
    }

    /** @return list<array<string, mixed>> */
    public function read(Competencia $competence): array
    {
        return $this->staging->executeQuery(
            'SELECT t.id, t.user_id, t.project_id, t.duration, t.billable,
                CONCAT(\'kimai:\', t.user_id) AS member_id,
                COALESCE(NULLIF(c.vat_id, \'\'), CONCAT(\'kimai-customer:\', c.id)) AS customer_reference
             FROM timesheet t
             JOIN users u ON u.id = t.user_id
             JOIN projects p ON p.id = t.project_id
             JOIN customers c ON c.id = p.customer_id
             WHERE t.begin >= :start AND t.begin < :end
             ORDER BY t.id',
            [
                'start' => $competence->start()->format('Y-m-d H:i:s'),
                'end' => $competence->endExclusive()->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();
    }
}
