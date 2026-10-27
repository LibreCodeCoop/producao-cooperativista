<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Akaunting;

use InvalidArgumentException;

/** Per-installation configuration, not a global rule for every cooperative. */
final readonly class CapitalSocialSettings
{
    public function __construct(
        public int $categoryId,
        public int $itemId,
    ) {
        if ($categoryId <= 0 || $itemId <= 0) {
            throw new InvalidArgumentException('Categorias e itens de capital social precisam de IDs positivos.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::readId('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID', 25),
            self::readId('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID', 11)
        );
    }

    private static function readId(string $name, int $previousDefault): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $previousDefault;
        }
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf('Valor inválido para a configuração %s.', $name));
        }
        return (int) $value;
    }
}
