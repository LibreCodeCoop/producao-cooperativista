<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain\Source;

use Closure;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read a complete Kimai work month without writing to Kimai or Akaunting.
 * The injected page fetcher is the only I/O boundary and must return
 * ['records' => list<array>, 'total_pages' => ?int].
 */
final class KimaiTimesheetReader
{
    private const int PAGE_SIZE = 50;
    private const int MAX_PAGES = 10000;

    /** @var Closure(array<string, int|string|null>): array{records: mixed, total_pages: ?int} */
    private Closure $fetchPage;

    /** @param callable(array<string, int|string|null>): array{records: mixed, total_pages: ?int} $fetchPage */
    public function __construct(callable $fetchPage)
    {
        $this->fetchPage = Closure::fromCallable($fetchPage);
    }

    /** @return array{entries: list<array<string, mixed>>, source_sha256: string} */
    public function readMonth(string $workedCompetence): array
    {
        if (preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $workedCompetence) !== 1) {
            throw new InvalidArgumentException('Invalid worked competence.');
        }

        $monthStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $workedCompetence . '-01');
        if ($monthStart === false) {
            throw new InvalidArgumentException('Invalid work month.');
        }

        $entries = [];
        $seen = [];
        $expectedPages = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = ($this->fetchPage)([
                'order' => 'ASC',
                'begin' => $monthStart->format('Y-m-d') . 'T00:00:00',
                'end' => $monthStart->format('Y-m-t') . 'T23:59:59',
                'user' => 'all',
                'page' => $page,
                'size' => self::PAGE_SIZE,
            ]);
            $rows = $result['records'] ?? null;
            $totalPages = $result['total_pages'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
                throw new DomainException('Invalid Kimai timesheet page.');
            }
            if ($totalPages !== null && (!is_int($totalPages) || $totalPages < 0
                || $totalPages > self::MAX_PAGES)) {
                throw new DomainException('Invalid Kimai page count.');
            }
            if ($totalPages === 0 && $rows !== []) {
                throw new DomainException('Kimai declared no pages but returned entries.');
            }
            if ($expectedPages !== null && $totalPages !== $expectedPages) {
                throw new DomainException('Kimai pagination changed during capture.');
            }
            if ($totalPages !== null) {
                $expectedPages = $totalPages;
            }
            if ($expectedPages !== null && $page > max(1, $expectedPages)) {
                throw new DomainException('Kimai returned an unexpected page.');
            }
            if ($rows === [] && ($expectedPages === null || $page < $expectedPages) && $page > 1) {
                if ($expectedPages !== null) {
                    throw new DomainException('Kimai pagination ended before its declared last page.');
                }
                break;
            }
            if ($rows === [] && $expectedPages !== null && $expectedPages > 0) {
                throw new DomainException('Kimai returned an empty declared page.');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] <= 0
                    || !is_int($row['user'] ?? null) || $row['user'] <= 0) {
                    throw new DomainException('Kimai timesheet without valid source or member ID.');
                }
                $id = $row['id'];
                if (isset($seen[$id])) {
                    throw new DomainException('Duplicate Kimai timesheet ID across pages.');
                }
                $seen[$id] = true;
                // Preserve the fields consumed by the existing Symfony importer.
                // Do not introduce defaults for missing work or monetary fields.
                $entry = [];
                foreach ([
                    'id', 'activity', 'project', 'user', 'begin', 'end', 'duration',
                    'description', 'rate', 'internalRate', 'exported', 'billable',
                ] as $key) {
                    if (!array_key_exists($key, $row)) {
                        throw new DomainException('Kimai timesheet is missing required source fields.');
                    }
                    $entry[$key] = $row[$key];
                }
                $entries[] = $entry;
            }
            if ($expectedPages !== null && $page >= max(1, $expectedPages)) {
                break;
            }
            if ($expectedPages === null && count($rows) < self::PAGE_SIZE) {
                break;
            }
            if ($page === self::MAX_PAGES) {
                throw new RuntimeException('Kimai timesheet pagination exceeded the safe limit.');
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $manifest = json_encode(
            ['schema_version' => 1, 'worked_competence' => $workedCompetence, 'entries' => $entries],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return ['entries' => $entries, 'source_sha256' => hash('sha256', $manifest)];
    }

    /**
     * Kimai has no immutable monthly revision in the inspected legacy API.
     * Two independently fetched matching manifests reduce, but cannot remove,
     * the risk of a concurrent edit across paginated pages.
     *
     * @return array{entries: list<array<string, mixed>>, source_sha256: string}
     */
    public function readStableMonth(string $workedCompetence): array
    {
        $first = $this->readMonth($workedCompetence);
        $second = $this->readMonth($workedCompetence);
        if (!hash_equals($first['source_sha256'], $second['source_sha256'])) {
            throw new DomainException('Kimai month changed during source capture; review and import again.');
        }

        return $second;
    }
}
