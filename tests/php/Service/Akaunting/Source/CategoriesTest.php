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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CategoriesTest extends TestCase
{
    public function testSaveListKeepsActiveSourceCategories(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->exactly(2))->method('find')->with(Category::class, $this->anything())->willReturn(null);
        $manager->expects($this->exactly(2))->method('persist');
        $manager->expects($this->exactly(2))->method('flush');
        $manager->expects($this->never())->method('remove');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $manager->method('getConnection')->willReturn($connection);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);
        $manager->expects($this->once())->method('getRepository')->with(Category::class)->willReturn($repository);

        $dataset = $this->createStub(Dataset::class);
        $dataset->method('list')->willReturn([
            ['id' => 10, 'name' => 'Cliente', 'parent_id' => null, 'type' => 'income', 'enabled' => 1],
            ['id' => 11, 'name' => 'Cliente interno', 'parent_id' => 10, 'type' => 'income', 'enabled' => 1],
        ]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());
        $categories->saveList();
        self::assertSame([10, 11], $categories->getChildrensCategories(10));
    }

    public function testRetiredCategoryIsRemovedAfterReferencesAreMigrated(): void
    {
        $retired = new Category();
        $retired->setId(20)->setName('Cliente > Externo > Serviço externo')
            ->setType('income')->setEnabled(1)->setParentId(45)->setMetadata(['id' => 20]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);
        $manager->expects($this->exactly(2))->method('persist');
        $manager->expects($this->once())->method('remove')->with($retired);
        $manager->expects($this->exactly(2))->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                [19, 'Cliente > Externo > Serviço', 'income', 20],
            );
        $connection->expects($this->exactly(2))->method('fetchOne')->willReturn(0);
        $manager->method('getConnection')->willReturn($connection);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$retired]);
        $manager->expects($this->once())->method('getRepository')->with(Category::class)->willReturn($repository);

        $dataset = $this->createStub(Dataset::class);
        $dataset->method('list')->willReturn([
            ['id' => 45, 'name' => 'Cliente > Externo', 'parent_id' => 36, 'type' => 'income', 'enabled' => 0],
            ['id' => 19, 'name' => 'Cliente > Externo > Serviço', 'parent_id' => 45, 'type' => 'income', 'enabled' => 1],
        ]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());
        $categories->saveList();
        self::assertSame([45, 19], $categories->getChildrensCategories(45));
    }

    public function testUnmappedRetirementWithReferencesFailsWithoutDeletion(): void
    {
        $retired = new Category();
        $retired->setId(999)->setName('Old category')->setType('income')->setEnabled(1)
            ->setParentId(null)->setMetadata(['id' => 999]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);
        $manager->expects($this->once())->method('persist');
        $manager->expects($this->once())->method('flush');
        $manager->expects($this->never())->method('remove');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');
        $connection->expects($this->once())->method('fetchOne')->willReturn(1);
        $manager->method('getConnection')->willReturn($connection);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([$retired]);
        $manager->method('getRepository')->willReturn($repository);

        $dataset = $this->createStub(Dataset::class);
        $dataset->method('list')->willReturn([
            ['id' => 19, 'name' => 'Cliente > Externo > Serviço', 'parent_id' => 45, 'type' => 'income', 'enabled' => 1],
        ]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('require reconciliation');
        $categories->saveList();
    }

    public function testEmptySourceCannotRemoveExistingCategories(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('getConnection');
        $manager->expects($this->never())->method('flush');
        $dataset = $this->createStub(Dataset::class);
        $dataset->method('list')->willReturn([]);
        $categories = new Categories(new NullLogger(), $manager, $dataset, new BatchPersistence());

        $this->expectException(\Exception::class);
        $categories->saveList();
    }
}
