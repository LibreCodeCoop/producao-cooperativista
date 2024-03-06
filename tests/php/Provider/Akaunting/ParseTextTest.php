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

use PHPUnit\Framework\Attributes\DataProvider;
use ProducaoCooperativista\Provider\Akaunting\ParseText;
use Tests\Php\TestCase;

final class ParseTextTest extends TestCase
{
    #[DataProvider('providerDo')]
    public function testDo(string $text, array $expected): void
    {
        $parseText = new ParseText();
        $actual = $parseText->do($text);
        $this->assertEquals($expected, $actual);
    }

    public static function providerDo(): array
    {
        return [
            ["", []],
            ["a", []],
            ["a:b", []],
            ["NFSe:b", []],
            ["nfse: b", []],
            ["NFSe: b", ['nfse' => 'b']],
            [" NFSe: b", ['nfse' => 'b']],
            [" NFSe:   b", ['nfse' => 'b']],
            [" NFSe:   b      ", ['nfse' => 'b']],
            ['Transação do mês: a', ['transaction_of_month' => 'a']],
            ['Transação do mês: a', ['transaction_of_month' => 'a']],
            ["NFSe: a\nTransação do mês: a", ['nfse' => 'a', 'transaction_of_month' => 'a']],
            ["NFSe:a\nTransação do mês: a", ['transaction_of_month' => 'a']],
            ["NFSe: a\n\naaa\n\nTransação do mês: a", ['nfse' => 'a', 'transaction_of_month' => 'a']],
        ];
    }
}
