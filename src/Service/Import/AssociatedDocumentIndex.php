<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Load invoice metadata once per unique document ID before importing payments.
 * Missing invoices are represented by a missing entry, not queried repeatedly.
 */
final class AssociatedDocumentIndex
{
    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function byId(EntityManagerInterface $manager, array $ids): array
    {
        $documents = [];
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $ids),
            static fn (int $id): bool => $id > 0
        )));
        foreach (array_chunk($ids, 400) as $batch) {
            $records = $manager->getConnection()->executeQuery(
                'SELECT id, customer_reference, metadata FROM invoices WHERE id IN (:ids) AND customer_reference IS NOT NULL',
                ['ids' => $batch],
                ['ids' => ArrayParameterType::INTEGER]
            )->fetchAllAssociative();
            foreach ($records as $row) {
                $documents[(int) $row['id']] = $row;
            }
        }
        return $documents;
    }
}
