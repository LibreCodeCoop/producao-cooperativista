<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\NetWithdrawalGoal;
use PHPUnit\Framework\TestCase;

final class NetWithdrawalGoalTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['PRODUCAO_META_LIQUIDA_MENSAL', 'PRODUCAO_META_HORAS_REFERENCIA'] as $key) {
            $this->original[$key] = getenv($key);
        }
        putenv('PRODUCAO_META_LIQUIDA_MENSAL=50000');
        putenv('PRODUCAO_META_HORAS_REFERENCIA=165');
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /** @return array<string,mixed> */
    private static function row(int $hours, float $pool, string $tax = '12345678901'): array
    {
        return [
            'tax_number' => $tax, 'dependentes' => 0,
            'hours_seconds' => $hours * 3600, 'base_producao' => $pool,
        ];
    }

    public function testGoalIsProportionalToHoursAndDoesNotRedistributeExistingValues(): void
    {
        $rows = [self::row(165, 30000), self::row(330, 80000)];
        $before = $rows;
        $calculator = new NetWithdrawalGoal();
        $result = $calculator->evaluate($rows, 2026, 8);
        $single = $calculator->evaluate([self::row(165, 0)], 2026, 8);
        $double = $calculator->evaluate([self::row(330, 0)], 2026, 8);
        self::assertSame($before, $rows);
        self::assertSame(50000.0, $result['net_target']);
        self::assertSame(165, $result['reference_hours']);
        self::assertSame(110000.0, $result['available_pool']);
        self::assertGreaterThan(0, $result['required_pool']);
        self::assertEqualsWithDelta($single['required_pool'] + $double['required_pool'], $result['required_pool'], 0.02);
        self::assertGreaterThan($single['required_pool'], $double['required_pool']);
        self::assertSame('simulation_only', $result['mode']);
        self::assertSame(2, $result['members']);
        self::assertEqualsWithDelta(
            abs($result['required_pool'] - $result['available_pool']),
            max($result['shortfall'], $result['potential_surplus']),
            0.02
        );
    }

    public function testZeroHoursAndPjAreNotAssignedPfTargets(): void
    {
        $result = new NetWithdrawalGoal()->evaluate([
            self::row(0, 1000),
            self::row(165, 30000, '12345678000199'),
        ], 2026, 8);
        self::assertSame(0, $result['members']);
        self::assertSame(0.0, $result['required_pool']);
        self::assertSame(31000.0, $result['available_pool']);
        self::assertSame(31000.0, $result['potential_surplus']);
    }

    public function testGoalInputIsValidatedWithoutAffectingFinancialWorkflow(): void
    {
        putenv('PRODUCAO_META_HORAS_REFERENCIA=0');
        $this->expectException(\DomainException::class);
        new NetWithdrawalGoal()->evaluate([], 2026, 8);
    }
}
