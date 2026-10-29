<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\DecimalAllocation;
use PHPUnit\Framework\TestCase;

final class DecimalAllocationTest extends TestCase
{
    public function testFractionsAndTiesUseStableLargestRemainder(): void
    {
        $calculator = new DecimalAllocation();
        $first = $calculator->allocate('0.05', ['c' => 1, 'a' => 1, 'b' => 1]);
        self::assertSame(['c' => 0.01, 'a' => 0.02, 'b' => 0.02], $first);

        $reversed = $calculator->allocate('0.05', ['b' => 1, 'a' => 1, 'c' => 1]);
        ksort($first);
        ksort($reversed);
        self::assertSame($first, $reversed);
    }

    public function testNoBinaryFloatDriftAccumulatesAtCurrencyBoundary(): void
    {
        self::assertSame(11, DecimalAllocation::cents('0.105'));
        self::assertSame(-11, DecimalAllocation::cents('-0.105'));
        self::assertSame(10001, DecimalAllocation::cents(100.01));

        $parts = (new DecimalAllocation())->allocate('100.01', ['a' => '1', 'b' => '2']);
        self::assertSame(['a' => 33.34, 'b' => 66.67], $parts);
        self::assertSame(10001, array_sum(array_map(DecimalAllocation::cents(...), $parts)));
    }

    public function testZeroAndNoWeightAreUnambiguous(): void
    {
        self::assertSame([], (new DecimalAllocation())->allocate('0.00', []));
        self::assertSame(['a' => 0.0], (new DecimalAllocation())->allocate('0.00', ['a' => 1]));
    }

    public function testPositiveAmountWithoutHoursIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (new DecimalAllocation())->allocate('0.01', ['a' => 0]);
    }
}
