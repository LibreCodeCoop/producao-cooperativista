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
        new CustomerCostAllocator()->allocate([
            ['amount' => 100.0, 'customer_reference' => 'client-a'],
        ], ['client-a' => 200.0]);
    }

    public function testCostsWithoutPositiveReceiptsAreIdentifiedButNotReallocated(): void
    {
        $calculator = new CustomerCostAllocator();
        $receipts = [
            ['amount' => 250.0, 'customer_reference' => 'paid-client'],
            ['amount' => 0.0, 'customer_reference' => 'not-yet-paid-client'],
        ];
        $costs = ['paid-client' => 35.0, 'not-yet-paid-client' => 20.0, 'unknown-client' => 15.0];
        self::assertSame([
            'not-yet-paid-client' => 20.0,
            'unknown-client' => 15.0,
        ], $calculator->unmatchedCosts($receipts, $costs));
        // An expense assigned to an unpaid customer is not silently paid from
        // another customer's work; the allocator must still reject that case.
        $this->expectException(DomainException::class);
        $calculator->allocate($receipts, $costs);
    }

    public function testAnInvoiceCanHaveZeroCostAndPreservesItsAmount(): void
    {
        $this->assertSame([0 => 0.0], new CustomerCostAllocator()->allocate([
            ['amount' => 0, 'customer_reference' => 'client-a'],
        ], []));
    }
}
