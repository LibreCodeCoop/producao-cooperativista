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

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ProducaoCooperativista\Helper\Dates;
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

    public static function providerCalculaPrevisaoPagamentoFrra(): array
    {
        return [
            ['2023-01-01', 5, '2023-12-01'],
            ['2023-11-01', 5, '2023-12-01'],
            ['2023-12-01', 5, '2024-12-01'],
        ];
    }
}
