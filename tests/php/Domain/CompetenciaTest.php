<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Competencia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompetenciaTest extends TestCase
{
    #[DataProvider('months')]
    public function testPeriodBoundariesAndFinancialPeriodAreDeterministic(
        string $month,
        string $following,
    ): void {
        $competence = Competencia::fromString($month);
        self::assertSame($month, (string) $competence);
        self::assertSame($month . '-01', $competence->start()->format('Y-m-d'));
        self::assertSame($following . '-01', $competence->endExclusive()->format('Y-m-d'));
        self::assertSame($following, $competence->financialMonth());
        $mutable = $competence->start();
        $mutable->modify('+9 months');
        self::assertSame($month, (string) $competence);
    }

    public static function months(): array
    {
        return [
            ['2024-02', '2024-03'],
            ['2025-12', '2026-01'],
            ['2026-01', '2026-02'],
            ['2023-01', '2023-02'],
        ];
    }

    #[DataProvider('invalidMonths')]
    public function testInvalidMonthsAreRejectedRatherThanNormalized(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM');
        Competencia::fromString($value);
    }

    public static function invalidMonths(): array
    {
        return [['2025-00'], ['2025-13'], ['2025-1'], ['2025-12-01'], ['2025-02garbage'], [''], ['25-12']];
    }
}
