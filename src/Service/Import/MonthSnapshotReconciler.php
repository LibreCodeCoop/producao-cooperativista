<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A complete Kimai month snapshot can remove stale entries that an upsert
 * alone would retain after an upstream edit or deletion. Scoped by begin date.
 */
final class MonthSnapshotReconciler
{
    /**
     * @param list<int|string> $sourceIds
     * @return int Number of removed ETL timesheets
     */
    public function removeMissingTimesheets(
        EntityManagerInterface $manager,
        DateTimeInterface $month,
        array $sourceIds,
    ): int {
        $start = \DateTimeImmutable::createFromInterface($month)
            ->modify('first day of this month')->setTime(0, 0);
        $end = $start->modify('first day of next month');
        $connection = $manager->getConnection();
        $existing = $connection->executeQuery(
            'SELECT id FROM timesheet WHERE begin >= :start AND begin < :end',
            [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        )->fetchFirstColumn();

        $source = array_fill_keys(array_map(intval(...), $sourceIds), true);
        $stale = [];
        foreach ($existing as $id) {
            $key = (int) $id;
            if (!isset($source[$key])) {
                $stale[] = $key;
            }
        }

        $removed = 0;
        foreach (array_chunk($stale, 400) as $batch) {
            $removed += (int) $connection->executeStatement(
                'DELETE FROM timesheet WHERE id IN (:ids)',
                ['ids' => $batch],
                ['ids' => ArrayParameterType::INTEGER]
            );
        }

        return $removed;
    }
}
