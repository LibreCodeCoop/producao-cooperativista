<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\Competencia;
use App\Service\Import\StagingSchema;
use Doctrine\DBAL\Connection;

/**
 * Reads only the ETL staging schema. It cannot access the Akaunting database.
 * Period-specific financial rows are keyed by the following accounting month
 * as in Movimentacao; dimension tables remain the current snapshot.
 */
final readonly class StagingInputReader
{
    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(private Connection $main)
    {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function read(Competencia $competence): array
    {
        $datasets = [];
        foreach (StagingSchema::TABLES as $table) {
            $where = '';
            $parameters = [];
            if ($table === 'timesheet') {
                $where = ' WHERE begin >= :start AND begin < :end';
                $parameters = [
                    'start' => $competence->start()->format('Y-m-d H:i:s'),
                    'end' => $competence->endExclusive()->format('Y-m-d H:i:s'),
                ];
            } elseif ($table === 'transactions' || $table === 'invoices') {
                $where = ' WHERE transaction_of_month = :financialMonth';
                $parameters = ['financialMonth' => $competence->financialMonth()];
            }
            $sql = 'SELECT * FROM ' . $this->main->quoteIdentifier($table) . $where;
            $datasets[$table] = $this->main->executeQuery($sql, $parameters)->fetchAllAssociative();
        }
        return $datasets;
    }
}
