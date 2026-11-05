<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Source;

use App\Entity\Producao\Category;
use App\Provider\Akaunting\Dataset;
use App\Service\Akaunting\Source\Categories;
use App\Service\Import\BatchPersistence;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CategoriesTest extends TestCase
{
    public function testSaveListFlushesOnceForSeveralCategories(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->exactly(2))->method('find')->with(Category::class, $this->anything())->willReturn(null);
        $manager->expects($this->exactly(2))->method('persist');
        $manager->expects($this->once())->method('flush');

        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn([
            ['id' => 10, 'name' => 'Cliente', 'parent_id' => null, 'type' => 'income', 'enabled' => 1],
            ['id' => 11, 'name' => 'Cliente interno', 'parent_id' => 10, 'type' => 'income', 'enabled' => 1],
        ]);

        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());
        $categories->saveList();

        $this->assertSame([10, 11], $categories->getChildrensCategories(10));
    }
}
