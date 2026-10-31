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

namespace App\Tests\Helper;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Helper\Dates;
use DateTime;
use Tests\Php\TestCase;

final class DatesTest extends TestCase
{
    #[DataProvider('providerCalculaPrevisaoPagamentoFrra')]
    public function testCalculaPrevisaoPagamentoFrra(string $now, int $diaUtil, string $expected): void
    {
        $today = new DateTime($now);
        $today->setTime(00, 00, 00);

        $dates = new Dates(
            now: $today
        );

        $actual = $dates->getPrevisaoPagamentoFrra();

        $expected = new DateTime($expected);
        $expected->setTime(00, 00, 00);
        $expected = Carbon::parse($expected);
        $expected = $expected->addBusinessDays($diaUtil);

        $this->assertEquals($expected, $actual, 'Data de previsão de pagamento de FRRA inválida');
    }

    public function testSettingMonthDoesNotMutateCallerAndResetsDerivedDate(): void
    {
        $date = new DateTime('2026-10-25 18:30:00');
        $dates = new Dates(now: new DateTime('2026-10-25'));
        $dates->setInicio($date);
        self::assertSame('2026-10-25 18:30:00', $date->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-01', $dates->getInicio()->format('Y-m-d'));
        self::assertSame('2026-11-01', $dates->getInicioProximoMes()->format('Y-m-d'));

        $old = $dates->getDataPagamento();
        $dates->setInicio(new DateTime('2026-12-08'));
        $new = $dates->getDataPagamento();
        self::assertNotSame($old, $new);
        self::assertSame('2026-12-01', $dates->getInicio()->format('Y-m-d'));
        self::assertSame('2027-01-01', $dates->getInicioProximoMes()->format('Y-m-d'));
    }

    public function testFutureFrraMonthAfterYearRolloverDoesNotMutateCurrentDate(): void
    {
        $today = new DateTime('2026-11-26');
        $dates = new Dates(pagamentoFrraMesPadrao: 5, now: $today);
        self::assertSame('2026-11-26', $today->format('Y-m-d'));
        self::assertSame('2026-11-26', $dates->hoje()->format('Y-m-d'));
        self::assertSame('2027-05', $dates->getPrevisaoPagamentoFrra()->format('Y-m'));
    }

    public function testChangingFrraBusinessDayRecalculatesDueDate(): void
    {
        $dates = new Dates(now: new DateTime('2026-01-02'));
        $original = $dates->getPrevisaoPagamentoFrra()->format('Y-m-d');
        $dates->setDiaUtilPagamento(9);
        self::assertNotSame($original, $dates->getPrevisaoPagamentoFrra()->format('Y-m-d'));
    }

    public function testBackfillUsesExplicitEvaluationClockRatherThanComputerWallClock(): void
    {
        $asOf = new DateTime('2026-10-26 12:30:00');
        $dates = new Dates(now: $asOf);
        $dates->setInicio(new DateTime('2024-02-15 10:10:00'));

        self::assertSame('2024-02-01', $dates->getInicio()->format('Y-m-d'));
        self::assertSame('2026-10-26 12:30:00', $dates->getDataProcessamento()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-26 12:30:00', $dates->getDataPagamento()->format('Y-m-d H:i:s'));

        $asOf->modify('+8 years');
        self::assertSame('2026-10-26 12:30:00', $dates->hoje()->format('Y-m-d H:i:s'));
        $externalView = $dates->hoje();
        $externalView->modify('+1 year');
        self::assertSame('2026-10-26', $dates->hoje()->format('Y-m-d'));
    }

    public function testChangingReferenceClockForPreviewInvalidatesDerivedPaymentDates(): void
    {
        $dates = new Dates(now: new DateTime('2026-02-01'));
        $dates->setInicio(new DateTime('2024-01-01'));
        self::assertSame('2026-02-01', $dates->getDataPagamento()->format('Y-m-d'));
        $reference = new DateTime('2024-02-02');
        $dates->setEvaluationTime($reference);
        $reference->modify('+3 years');

        self::assertSame('2024-02-02', $dates->getDataProcessamento()->format('Y-m-d'));
        self::assertSame('2024-02-02', $dates->hoje()->format('Y-m-d'));
    }

    public function testScheduledPaymentDateForFutureMonthIsIndependentOfCurrentDate(): void
    {
        $january = new Dates(now: new DateTime('2026-10-20 09:00:00'));
        $february = new Dates(now: new DateTime('2026-11-22 14:00:00'));
        foreach ([$january, $february] as $dates) {
            $dates->setInicio(new DateTime('2027-11-17'));
            $dates->setDiaUtilPagamento(5);
        }
        self::assertSame(
            $january->getDataPagamento()->format('Y-m-d'),
            $february->getDataPagamento()->format('Y-m-d')
        );
        self::assertSame('2027-11', $january->getInicio()->format('Y-m'));
    }

    public static function providerCalculaPrevisaoPagamentoFrra(): array
    {
        return [
            ['2023-01-01', 5, '2023-12-01'],
            ['2023-11-01', 5, '2023-12-01'],
            ['2023-12-01', 5, '2024-12-01'],
        ];
    }
}
