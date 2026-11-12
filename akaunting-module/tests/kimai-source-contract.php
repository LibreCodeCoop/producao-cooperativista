<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require dirname(__DIR__) . '/Domain/Source/KimaiTimesheetReader.php';
require dirname(__DIR__) . '/Domain/Source/KimaiSnapshotImporter.php';

use Modules\CooperativeProduction\Domain\Source\KimaiTimesheetReader;
use Modules\CooperativeProduction\Domain\Source\KimaiSnapshotImporter;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $action, string $expected): void {
    try {
        $action();
    } catch (DomainException | InvalidArgumentException $error) {
        if (str_contains($error->getMessage(), $expected)) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected a failed source contract: ' . $expected);
};
$entry = static function (int $id, int $user = 100): array {
    return [
        'id' => $id, 'activity' => 5, 'project' => 9, 'user' => $user,
        'begin' => '2026-09-01T12:00:00', 'end' => '2026-09-01T13:00:00',
        'duration' => 3600, 'description' => '', 'rate' => 125.5,
        'internalRate' => 60.0, 'exported' => false, 'billable' => true,
    ];
};
$firstQueries = [];
$reader = new KimaiTimesheetReader(static function (array $query) use ($entry, &$firstQueries): array {
    $firstQueries[] = $query;
    return ['records' => [$entry(2), $entry(1)], 'total_pages' => 1];
});
$first = $reader->readStableMonth('2026-09');
$assert(count($firstQueries) === 2, 'Stability gate must read the complete month twice.');
$assert($firstQueries[0]['begin'] === '2026-09-01T00:00:00', 'Wrong work month start.');
$assert($firstQueries[0]['end'] === '2026-09-30T23:59:59', 'Wrong work month end.');
$assert($first['entries'][0]['id'] === 1 && $first['entries'][1]['id'] === 2, 'Entries not sorted by source ID.');
$assert($firstQueries[0]['user'] === 'all', 'Reader silently filtered cooperative members.');
$reordered = (new KimaiTimesheetReader(static fn (array $query): array => [
    'records' => [$entry(1), $entry(2)], 'total_pages' => 1,
]))->readMonth('2026-09');
$assert($first['source_sha256'] === $reordered['source_sha256'], 'Manifest must ignore source ordering.');
$twoPages = new KimaiTimesheetReader(static fn (array $query): array => [
    'records' => $query['page'] === 1 ? [$entry(1)] : [$entry(2)], 'total_pages' => 2,
]);
$assert(count($twoPages->readMonth('2026-09')['entries']) === 2, 'Did not retrieve the second declared page.');
$withoutHeader = new KimaiTimesheetReader(static fn (array $query): array => [
    'records' => $query['page'] === 1
        ? array_map($entry, range(1, 50)) : [],
    'total_pages' => null,
]);
$assert(count($withoutHeader->readMonth('2026-09')['entries']) === 50, 'Unknown page count must allow an empty terminal page.');
$expect(static function () use ($entry): void {
    (new KimaiTimesheetReader(static fn (array $query): array => [
        'records' => [$entry(1)], 'total_pages' => 2,
    ]))->readMonth('2026-09');
}, 'Duplicate Kimai');
$expect(static function () use ($entry): void {
    (new KimaiTimesheetReader(static fn (array $query): array => [
        'records' => $query['page'] === 1 ? [$entry(1)] : [], 'total_pages' => 2,
    ]))->readMonth('2026-09');
}, 'empty declared page');
$expect(static function () use ($entry): void {
    $calls = 0;
    (new KimaiTimesheetReader(static function (array $query) use ($entry, &$calls): array {
        $calls++;
        $row = $entry(1);
        if ($calls > 1) {
            $row['duration'] = 7200;
        }
        return ['records' => [$row], 'total_pages' => 1];
    }))->readStableMonth('2026-09');
}, 'changed during source capture');
$expect(static function () use ($entry): void {
    (new KimaiTimesheetReader(static fn (array $query): array => [
        'records' => [$entry(1, 0)], 'total_pages' => 1,
    ]))->readMonth('2026-09');
}, 'without valid source or member ID');
$expect(static function () use ($entry): void {
    (new KimaiTimesheetReader(static fn (array $query): array => [
        'records' => [$entry(1)], 'total_pages' => 1,
    ]))->readMonth('2026-13');
}, 'Invalid worked competence');

$reads = 0;
$importer = new KimaiSnapshotImporter(new KimaiTimesheetReader(
    static function (array $query) use ($entry, &$reads): array {
        $reads++;
        return ['records' => [$entry(1)], 'total_pages' => 1];
    },
));
$prepared = $importer->prepare(10, '2026-09', '2026-10', '2026-11', '2026-11-12 10:00:00');
$assert($reads === 2, 'Importer must read the full source twice.');
$assert($prepared['meta']['company_id'] === 10 && $prepared['meta']['source'] === 'kimai', 'Wrong provenance.');
$assert($prepared['meta']['external_revision'] === 'manifest-v1:' . $prepared['meta']['source_sha256'], 'Wrong immutable revision.');
$assert($prepared['meta']['financial_competence'] === '2026-10'
    && $prepared['meta']['payment_competence'] === '2026-11', 'Lost financial or payment competence.');
$assert($prepared['payload']['entries'][0]['id'] === 1, 'Importer lost observed work.');
$expect(static fn (): array => $importer->prepare(0, '2026-09', '2026-10', '2026-11', '2026-11-12 10:00:00'), 'Invalid company');
$assert($reads === 2, 'Invalid tenant must not trigger upstream access.');
$expect(static function () use ($entry): void {
    (new KimaiTimesheetReader(static fn (array $query): array => [
        'records' => [$entry(1)], 'total_pages' => 0,
    ]))->readMonth('2026-09');
}, 'declared no pages');

echo "PASS: Kimai read-only monthly manifest, paging, ordering, duplicate and drift gates\n";
