<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Persists imported entities in chunks, preventing a database flush per item.
 * Explicitly keeps the UnitOfWork alive; clearing it could detach dependent rows.
 */
final class BatchPersistence
{
    /**
     * @param iterable<object> $entities
     */
    public function save(EntityManagerInterface $manager, iterable $entities, int $batchSize = 250): void
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('O tamanho do lote deve ser maior que zero.');
        }

        $pending = 0;
        foreach ($entities as $entity) {
            $manager->persist($entity);
            ++$pending;
            if ($pending === $batchSize) {
                $manager->flush();
                $pending = 0;
            }
        }

        if ($pending > 0) {
            $manager->flush();
        }
    }
}
