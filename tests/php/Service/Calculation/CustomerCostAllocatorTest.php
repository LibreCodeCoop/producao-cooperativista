<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\CustomerCostAllocator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CustomerCostAllocatorTest extends TestCase
{
    public function testCostIsSharedBetweenMultipleInvoicesNotAppliedToFirst(): void
    {
        $calculator = new CustomerCostAllocator();
        $invoices = [
            'invoice:2' => ['amount' => 300.0, 'customer_reference' => 'client-a'],
            'invoice:1' => ['amount' => 100.0, 'customer_reference' => 'client-a'],
            'invoice:3' => ['amount' => 500.0, 'customer_reference' => 'client-b'],
        ];
        $costs = $calculator->allocate($invoices, ['client-a' => 120.0, 'client-b' => 50.0]);

        self::assertEqualsWithDelta(90.0, $costs['invoice:2'], 0.001);
        self::assertEqualsWithDelta(30.0, $costs['invoice:1'], 0.001);
        self::assertEqualsWithDelta(50.0, $costs['invoice:3'], 0.001);
        self::assertEqualsWithDelta(170.0, array_sum($costs), 0.001);
    }

    public function testExcessCostRequiresReconciliationRatherThanNegativePayout(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excedem receitas');
        (new CustomerCostAllocator())->allocate([
            ['amount' => 100.0, 'customer_reference' => 'client-a'],
        ], ['client-a' => 200.0]);
    }

    public function testAnInvoiceCanHaveZeroCostAndPreservesItsAmount(): void
    {
        $this->assertSame([0 => 0.0], (new CustomerCostAllocator())->allocate([
            ['amount' => 0, 'customer_reference' => 'client-a'],
        ], []));
    }
}
