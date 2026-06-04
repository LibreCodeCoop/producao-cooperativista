<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 *
 * @author LibreCode contributors
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

namespace App\Service\Akaunting;

use JsonException;
use UnexpectedValueException;

final class DocumentConfiguration
{
    /**
     * @return int[]
     */
    public function getDocumentItemIds(): array
    {
        return array_map(
            static fn (mixed $itemId): int => (int) $itemId,
            $this->parseJsonEnvironment('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS')
        );
    }

    public function getDocumentItemId(string $code): int
    {
        $itemIds = $this->getDocumentItemIds();
        if (!array_key_exists($code, $itemIds)) {
            throw new UnexpectedValueException(sprintf(
                'Environment variable AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS must define item id for code "%s".',
                $code
            ));
        }

        return $itemIds[$code];
    }

    /**
     * @return array<mixed>
     */
    public function getTaxData(string $whoami): array
    {
        return $this->parseJsonEnvironment('AKAUNTING_IMPOSTOS_' . $whoami);
    }

    public function getTaxDataInt(string $whoami, string $key): int
    {
        $taxData = $this->getTaxData($whoami);
        $environmentVariableName = 'AKAUNTING_IMPOSTOS_' . $whoami;
        if (!array_key_exists($key, $taxData)) {
            throw new UnexpectedValueException(sprintf(
                'Environment variable %s must define "%s".',
                $environmentVariableName,
                $key
            ));
        }

        return (int) $taxData[$key];
    }

    /**
     * @return array<mixed>
     */
    private function parseJsonEnvironment(string $name): array
    {
        $rawValue = getenv($name);
        if ($rawValue === false) {
            throw new UnexpectedValueException(sprintf(
                'Environment variable %s is not set.',
                $name
            ));
        }

        $value = trim((string) $rawValue);
        if ($value === '') {
            throw new UnexpectedValueException(sprintf(
                'Environment variable %s is empty.',
                $name
            ));
        }

        $firstCharacter = $value[0] ?? '';
        $lastCharacter = $value[strlen($value) - 1] ?? '';
        if (
            strlen($value) >= 2
            && ($firstCharacter === '"' || $firstCharacter === "'")
            && $firstCharacter === $lastCharacter
        ) {
            $value = substr($value, 1, -1);
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(sprintf(
                'Environment variable %s must contain valid JSON. %s',
                $name,
                $exception->getMessage()
            ), 0, $exception);
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new UnexpectedValueException(sprintf(
            'Environment variable %s must contain a JSON object.',
            $name
        ));
    }
}
