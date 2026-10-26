<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

/**
 * In-memory index of category relationships. No database query is performed
 * while resolving descendants and accidental cycles cannot recurse forever.
 */
final class CategoryHierarchy
{
    /** @var array<int, list<int>> */
    private array $childrenByParent = [];

    /** @var array<int, true> */
    private array $knownCategoryIds = [];

    /** @var array<int, list<int>> */
    private array $descendantsCache = [];

    /**
     * @param array<int, int|null> $parentByCategory
     */
    public function __construct(array $parentByCategory)
    {
        foreach ($parentByCategory as $categoryId => $parentId) {
            $categoryId = (int) $categoryId;
            $this->knownCategoryIds[$categoryId] = true;
            if ($parentId !== null) {
                $this->childrenByParent[(int) $parentId][] = $categoryId;
            }
        }
    }

    /**
     * @return list<int> Root included when it exists.
     */
    public function descendants(int $categoryId): array
    {
        if (array_key_exists($categoryId, $this->descendantsCache)) {
            return $this->descendantsCache[$categoryId];
        }

        $result = [];
        $visited = [];
        $pending = [$categoryId];
        while ($pending) {
            $current = array_pop($pending);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (!isset($this->knownCategoryIds[$current])) {
                continue;
            }
            $result[] = $current;
            foreach (array_reverse($this->childrenByParent[$current] ?? []) as $child) {
                $pending[] = $child;
            }
        }

        return $this->descendantsCache[$categoryId] = $result;
    }
}
