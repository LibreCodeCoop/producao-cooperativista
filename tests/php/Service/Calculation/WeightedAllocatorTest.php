<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\WeightedAllocator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WeightedAllocatorTest extends TestCase
{
    private WeightedAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new WeightedAllocator();
    }

    public function testRepeatedTimesheetsDoNotInflateDenominator(): void
    {
        $weights = $this->allocator->weightsFromRows([
            ['tax_number' => 'a', 'trabalhado' => 10, 'peso' => 1],
            ['tax_number' => 'b', 'trabalhado' => 10, 'peso' => 1],
            ['tax_number' => 'b', 'trabalhado' => 10, 'peso' => 1],
        ], 'Cliente 1');

        $this->assertSame(['a' => 10.0, 'b' => 20.0], $weights);
        $allocation = $this->allocator->allocate(9000, $weights, 'Cliente 1');

        $this->assertEqualsWithDelta(3000, $allocation['a'], 0.000001);
        $this->assertEqualsWithDelta(6000, $allocation['b'], 0.000001);
        $this->assertEqualsWithDelta(9000, array_sum($allocation), 0.000001);
    }

    public function testWeightAndDurationDetermineSharesAndPreserveAmount(): void
    {
        $weights = $this->allocator->weightsFromRows([
            ['tax_number' => 'a', 'trabalhado' => 60, 'peso' => 1],
            ['tax_number' => 'b', 'trabalhado' => 60, 'peso' => 2],
            ['tax_number' => 'c', 'trabalhado' => 0, 'peso' => 1],
        ], 'Produção mensal');

        $allocation = $this->allocator->allocate(100.01, $weights, 'Produção mensal');

        $this->assertEqualsWithDelta(100.01 / 3, $allocation['a'], 0.000001);
        $this->assertEqualsWithDelta(100.01 * 2 / 3, $allocation['b'], 0.000001);
        $this->assertSame(0.0, $allocation['c']);
        $this->assertEqualsWithDelta(100.01, array_sum($allocation), 0.000001);
    }

    public function testRejectsInvalidWeightBeforeMultiplication(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('peso inválido');

        $this->allocator->weightsFromRows([['tax_number' => 'a', 'trabalhado' => 3600, 'peso' => -2]], 'Teste');
    }

    public function testRejectsMissingCooperadoId(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sem identificação');

        $this->allocator->weightsFromRows([['trabalhado' => 3600, 'peso' => 1]], 'Teste');
    }

    public function testRejectsPositiveDistributionWithoutHours(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('não há horas ponderadas');

        $this->allocator->allocate(1000, ['a' => 0.0], 'Cliente 1');
    }

    public function testRejectsNegativeAvailableAmountInsteadOfGeneratingDebit(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('capital elegível');

        $this->allocator->allocate(-100, ['a' => 1.0], 'Cliente 1');
    }

    public function testDoesNotAllocateZeroToUnidentifiedCooperado(): void
    {
        $this->assertSame([], $this->allocator->allocate(0, [], 'Sem produção'));
    }
}
