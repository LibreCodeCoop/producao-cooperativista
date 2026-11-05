<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Load existing records by batches, not one SELECT per imported row.
 */
final class ExistingEntityIndex
{
    /**
     * @param class-string $entityClass
     * @param array<int, int|string> $ids
     * @return array<int|string, object>
     */
    public function byId(EntityManagerInterface $manager, string $entityClass, array $ids): array
    {
        $existing = [];
        $ids = array_values(array_unique($ids));
        foreach (array_chunk($ids, 400) as $batch) {
            foreach ($manager->getRepository($entityClass)->findBy(['id' => $batch]) as $entity) {
                $existing[$entity->getId()] = $entity;
            }
        }
        return $existing;
    }
}
