<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\BatchPersistence;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BatchPersistenceTest extends TestCase
{
    public function testBatchesUseOneFlushForEachChunkAndTail(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->exactly(5))->method('persist');
        $manager->expects($this->exactly(3))->method('flush');

        (new BatchPersistence())->save($manager, [
            new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(),
        ], 2);
    }

    public function testDoesNotFlushEmptyDataset(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->never())->method('flush');

        (new BatchPersistence())->save($manager, []);
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BatchPersistence())->save($this->createMock(EntityManagerInterface::class), [], 0);
    }
}
