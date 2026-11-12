<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain;

use InvalidArgumentException;

/** Validate worked-competence filters before constructing a tenant-scoped query. */
final class MemberHistoryFilters
{
    /** @return array{from: ?string, to: ?string} */
    public static function parse(mixed $from, mixed $to): array
    {
        $from = self::month($from);
        $to = self::month($to);
        if ($from !== null && $to !== null && strcmp($from, $to) > 0) {
            throw new InvalidArgumentException('The first worked month cannot follow the last.');
        }

        return ['from' => $from, 'to' => $to];
    }

    private static function month(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid worked-competence month.');
        }

        return $value;
    }
}
