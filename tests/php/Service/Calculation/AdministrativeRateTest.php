<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\AdministrativeRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdministrativeRateTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'base baixo, piso supera referência' => [10000.0, 3000.0, 25, 3000.0, 30.0];
        yield 'seguranca entre piso e teto' => [10000.0, 2000.0, 25, 2500.0, 25.0];
        yield 'referencia superior ao dobro' => [20000.0, 2000.0, 25, 4000.0, 20.0];
        yield 'igual ao piso' => [8000.0, 2000.0, 25, 2000.0, 25.0];
        yield 'igual ao dobro' => [16000.0, 2000.0, 25, 4000.0, 25.0];
        yield 'sem gastos' => [10000.0, 0.0, 25, 0.0, 0.0];
        yield 'base zerada sem gastos' => [0.0, 0.0, 25, 0.0, 0.0];
        yield 'reference zero with costs' => [10000.0, 2000.0, 0, 2000.0, 20.0];
        yield 'reference 100 with costs' => [10000.0, 2000.0, 100, 4000.0, 40.0];
        yield 'real effective percentage 27.45' => [10000.0, 2745.0, 25, 2745.0, 27.45];
    }

    #[DataProvider('scenarios')]
    public function testOriginalConditionalBranches(
        float $base,
        float $expenses,
        int $reference,
        float $expectedRate,
        float $expectedEffectivePercent,
    ): void {
        $result = (new AdministrativeRate())->evaluate($base, $expenses, $reference);
        self::assertEqualsWithDelta($expectedRate, $result['rate'], 0.00001);
        self::assertEqualsWithDelta($expectedEffectivePercent, $result['effective_percent'], 0.00001);
        self::assertGreaterThanOrEqual($result['minimum'], $result['rate']);
        self::assertLessThanOrEqual($result['maximum'], $result['rate']);
    }

    public function testMissingBaseFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        (new AdministrativeRate())->evaluate(0.0, 1.0, 25);
    }

    public function testNegativeExpensesAreNotAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AdministrativeRate())->evaluate(500.0, -2.0, 25);
    }
}
