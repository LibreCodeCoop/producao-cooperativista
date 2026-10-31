<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\CategoryHierarchy;
use PHPUnit\Framework\TestCase;

final class CategoryHierarchyTest extends TestCase
{
    public function testHierarchicalLookupIncludesRootAndDescendants(): void
    {
        $hierarchy = new CategoryHierarchy([28 => 45, 52 => 28, 53 => 28, 54 => 52, 45 => null]);

        $this->assertSame([28, 52, 54, 53], $hierarchy->descendants(28));
        $this->assertSame([28, 52, 54, 53], $hierarchy->descendants(28));
        $this->assertSame([52, 54], $hierarchy->descendants(52));
        $this->assertSame([], $hierarchy->descendants(999));
    }

    public function testCycleCannotLoopIndefinitelyOrDuplicateCategories(): void
    {
        $hierarchy = new CategoryHierarchy([1 => 3, 2 => 1, 3 => 2]);

        $this->assertSame([1, 2, 3], $hierarchy->descendants(1));
    }
}
