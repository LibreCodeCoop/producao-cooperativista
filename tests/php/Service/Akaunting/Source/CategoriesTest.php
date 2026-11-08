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
use Doctrine\ORM\EntityRepository;
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
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);
        $manager->method('getRepository')->with(Category::class)->willReturn($repository);

        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn([
            ['id' => 10, 'name' => 'Cliente', 'parent_id' => null, 'type' => 'income', 'enabled' => 1],
            ['id' => 11, 'name' => 'Cliente interno', 'parent_id' => 10, 'type' => 'income', 'enabled' => 1],
        ]);

        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());
        $categories->saveList();

        $this->assertSame([10, 11], $categories->getChildrensCategories(10));
    }

    public function testRetiredSourceCategoryIsExcludedButKeptForHistory(): void
    {
        $retired = new Category();
        $retired->setId(20)->setName('Cliente > Externo > Serviço externo')
            ->setType('income')->setEnabled(1)->setParentId(45)->setMetadata(['id' => 20]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);
        $manager->expects($this->exactly(2))->method('persist');
        $manager->expects($this->exactly(2))->method('flush');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([$retired]);
        $manager->method('getRepository')->with(Category::class)->willReturn($repository);

        $dataset = $this->createMock(Dataset::class);
        $dataset->method('list')->willReturn([
            ['id' => 45, 'name' => 'Cliente > Externo', 'parent_id' => 36, 'type' => 'income', 'enabled' => 0],
            ['id' => 19, 'name' => 'Cliente > Externo > Serviço', 'parent_id' => 45, 'type' => 'income', 'enabled' => 1],
        ]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());
        $categories->saveList();

        self::assertTrue($retired->getMetadata()['source_retired']);
        self::assertSame(20, $retired->getId());
        self::assertSame([45, 19], $categories->getChildrensCategories(45));

        // Separate requests rebuild the hierarchy from staging. The retained
        // historical row may not become an eligible category again.
        $activeParent = new Category();
        $activeParent->setId(45)->setName('Cliente > Externo')->setType('income')
            ->setEnabled(0)->setParentId(36)->setMetadata(['id' => 45]);
        $activeService = new Category();
        $activeService->setId(19)->setName('Cliente > Externo > Serviço')->setType('income')
            ->setEnabled(1)->setParentId(45)->setMetadata(['id' => 19]);

        $nextRepository = $this->createMock(EntityRepository::class);
        $nextRepository->method('findAll')->willReturn([$activeParent, $activeService, $retired]);
        $nextManager = $this->createMock(EntityManagerInterface::class);
        $nextManager->method('getRepository')->willReturn($nextRepository);
        $reader = new Categories(new NullLogger(), $nextManager, $dataset, new BatchPersistence());
        self::assertSame([45, 19], $reader->getChildrensCategories(45));
    }

    public function testEmptySourceCannotRetireExistingCategories(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('getRepository');
        $manager->expects($this->never())->method('flush');
        $dataset = $this->createMock(Dataset::class);
        $dataset->method('list')->willReturn([]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());

        $this->expectException(\Exception::class);
        $categories->saveList();
    }

}
