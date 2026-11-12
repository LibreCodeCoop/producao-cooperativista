<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require dirname(__DIR__) . '/Domain/MemberHistoryFilters.php';

use Modules\CooperativeProduction\Domain\MemberHistoryFilters;

$valid = [
    [null, null, ['from' => null, 'to' => null]],
    ['', '', ['from' => null, 'to' => null]],
    ['2026-01', '2026-12', ['from' => '2026-01', 'to' => '2026-12']],
    ['2026-02', '2026-02', ['from' => '2026-02', 'to' => '2026-02']],
];
foreach ($valid as [$from, $to, $expected]) {
    if (MemberHistoryFilters::parse($from, $to) !== $expected) {
        throw new RuntimeException('Valid competence range was not preserved.');
    }
}

$invalid = [
    ['2026-00', null], ['2026-13', null], ['2026-2', null],
    ['2026-12', '2026-01'], [['2026-01'], null],
    ['2026-01 OR 1=1', null], ['2026-01', ['2026-12']],
];
foreach ($invalid as [$from, $to]) {
    try {
        MemberHistoryFilters::parse($from, $to);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid range was accepted.');
}

echo 'PASS: worked competence filters: 4 valid, 7 invalid' . PHP_EOL;
