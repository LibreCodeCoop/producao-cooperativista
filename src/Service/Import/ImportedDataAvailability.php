<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Competencia;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only source of truth for the closing wizard's available actions.
 * A category, invoice or user without work hours is not an auditable
 * cooperative-production import for the requested competence.
 */
final readonly class ImportedDataAvailability
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony DI. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $main,
    ) {
    }

    /**
     * @return array{competence: string, has_work_data: bool, has_staging_data: bool,
     *     work_entries: int, work_members: int, latest_imported_competence: ?string}
     */
    public function forCompetence(Competencia $competence): array
    {
        $rows = $this->main->fetchAssociative(
            'SELECT COUNT(*) AS entries, COUNT(DISTINCT user_id) AS members FROM timesheet '
            . 'WHERE begin >= :start AND begin < :end',
            [
                'start' => $competence->start()->format('Y-m-d'),
                'end' => $competence->endExclusive()->format('Y-m-d'),
            ],
        ) ?: [];

        $entries = (int) ($rows['entries'] ?? 0);
        $members = (int) ($rows['members'] ?? 0);
        $hasStagingData = array_any(StagingSchema::TABLES, fn ($table): bool => $this->main->fetchOne(
            'SELECT 1 FROM ' . $this->main->quoteIdentifier($table) . ' LIMIT 1'
        ) !== false);

        $latest = $this->main->fetchOne('SELECT MAX(begin) FROM timesheet');
        $latestMonth = is_string($latest)
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])-/', $latest) === 1
            ? substr($latest, 0, 7) : null;

        return [
            'competence' => (string) $competence,
            'has_work_data' => $entries > 0 && $members > 0,
            'has_staging_data' => $hasStagingData,
            'work_entries' => $entries,
            'work_members' => $members,
            'latest_imported_competence' => $latestMonth,
        ];
    }
}
