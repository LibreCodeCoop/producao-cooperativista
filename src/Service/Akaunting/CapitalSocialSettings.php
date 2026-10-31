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
            self::readId('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID'),
            self::readId('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID')
        );
    }

    private static function readId(string $name): int
    {
        $value = getenv($name);
        if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf('Configure %s com um ID positivo do Akaunting.', $name));
        }

        return (int) $value;
    }
}
