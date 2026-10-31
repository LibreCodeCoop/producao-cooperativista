<?php

/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace App\Service\Akaunting\Source;

use App\Entity\Producao\Category;
use App\Helper\MagicGetterSetterTrait;
use App\Provider\Akaunting\Dataset;
use App\Service\Calculation\CategoryHierarchy;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use App\Service\Import\BatchPersistence;

class Categories
{
    use MagicGetterSetterTrait;
    private int $companyId;
    /** @var Category[] */
    private array $list = [];
    private ?CategoryHierarchy $categoryHierarchy = null;

    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private Dataset $dataset,
        private BatchPersistence $batchPersistence,
    ) {
        $this->companyId = (int) getenv('AKAUNTING_COMPANY_ID');
    }

    /**
     * @return Category[]
     */
    public function getList(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->info('Baixando dados de categorias');

        $list = $this->dataset->list('/api/categories', [
            'company_id' => $this->getCompanyId(),
        ]);
        foreach ($list as $row) {
            $category = $this->fromArray($row);
            $this->list[] = $category;
        }
        $this->logger->info('Dados de categorias salvos com sucesso. Total: {total}', [
            'total' => count($this->list),
        ]);
        return $this->list ?? [];
    }

    public function fromArray(array $array): Category
    {
        $entity = $this->entityManager->find(Category::class, $array['id']);
        $array = $this->convertFields($array);
        if (!$entity instanceof Category) {
            $entity = new Category();
        }
        $entity->fromArray($array);
        return $entity;
    }

    public function saveList(): self
    {
        $this->getList();
        $this->batchPersistence->save($this->entityManager, $this->list);
        $this->categoryHierarchy = null;
        return $this;
    }

    public function saveRow(Category $taxes): self
    {
        $em = $this->entityManager;
        $em->persist($taxes);
        $em->flush();
        return $this;
    }

    private function convertFields(array $row): array
    {
        $row['metadata'] = $row;
        return $row;
    }

    public function getCategories(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->list = $this->entityManager->getRepository(Category::class)->findAll();
        if (empty($this->list)) {
            throw new Exception('Sem categorias');
        }
        return $this->list;
    }

    public function getChildrensCategories(int $id): array
    {
        if ($this->categoryHierarchy === null) {
            $parentByCategory = [];
            foreach ($this->getCategories() as $category) {
                $parentByCategory[$category->getId()] = $category->getParentId();
            }
            $this->categoryHierarchy = new CategoryHierarchy($parentByCategory);
        }

        return $this->categoryHierarchy->descendants($id);
    }
}
