<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain\Source;

use Modules\CooperativeProduction\Domain\SnapshotStore;

/**
 * Import only a stable, complete Kimai month into the native encrypted journal.
 * The company ID must be resolved and authorized by the backend caller.
 * This service never calculates compensation or publishes documents.
 */
final readonly class KimaiSnapshotImporter
{
    public function __construct(private KimaiTimesheetReader $reader)
    {
    }

    /**
     * @return array{meta: array<string, int|string>, payload: array<string, mixed>}
     */
    public function prepare(
        int $companyId,
        string $workedCompetence,
        string $financialCompetence,
        string $paymentCompetence,
        string $collectedAt,
    ): array {
        // The snapshot store performs the final tenant, date and source checks.
        // Validate before network access to avoid reading for an invalid tenant.
        if ($companyId <= 0 || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $workedCompetence) !== 1
            || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $financialCompetence) !== 1
            || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $paymentCompetence) !== 1) {
            throw new \DomainException('Invalid company or explicit competence.');
        }
        $stable = $this->reader->readStableMonth($workedCompetence);

        return [
            'meta' => [
                'company_id' => $companyId,
                'worked_competence' => $workedCompetence,
                'financial_competence' => $financialCompetence,
                'payment_competence' => $paymentCompetence,
                'source' => 'kimai',
                'external_revision' => 'manifest-v1:' . $stable['source_sha256'],
                'schema_version' => 'kimai-timesheets-v1',
                'source_sha256' => $stable['source_sha256'],
                'collected_at' => $collectedAt,
            ],
            'payload' => [
                'worked_competence' => $workedCompetence,
                'entries' => $stable['entries'],
            ],
        ];
    }

    public function capture(
        SnapshotStore $store,
        int $companyId,
        string $workedCompetence,
        string $financialCompetence,
        string $paymentCompetence,
        string $collectedAt,
    ): int {
        $capture = $this->prepare(
            $companyId,
            $workedCompetence,
            $financialCompetence,
            $paymentCompetence,
            $collectedAt,
        );

        return $store->capture($capture['meta'], $capture['payload']);
    }
}
