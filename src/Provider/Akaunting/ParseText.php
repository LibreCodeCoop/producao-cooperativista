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

namespace App\Provider\Akaunting;

class ParseText
{
    private array $dictionaryTextParams = [
        'NFSe' => 'nfse',
        'Transação do mês' => 'transaction_of_month',
        'Percentual desconto fixo' => 'discount_percentage',
        'CNPJ cliente' => 'customer',
        'Setor' => 'sector',
        'setor' => 'sector',
        'Arquivar' => 'archive',
    ];

    /**
     * @return string[]
     */
    public function do(string $text): array
    {
        $return = [];
        if (empty($text)) {
            return $return;
        }
        $explodedText = explode("\n", $text);
        $explodedText = array_map('trim', $explodedText);
        $pattern = '/^(?<paramName>' . implode('|', array_keys($this->dictionaryTextParams)) . '): (?<paramValue>.*)$/i';
        foreach ($explodedText as $row) {
            if (!preg_match($pattern, $row, $matches)) {
                continue;
            }
            if (!array_key_exists($matches['paramName'], $this->dictionaryTextParams)) {
                continue;
            }
            $return[$this->dictionaryTextParams[$matches['paramName']]] = strtolower(trim($matches['paramValue']));
        }
        return $return;
    }
}
