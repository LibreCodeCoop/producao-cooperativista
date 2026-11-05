<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Reporting;

use App\Domain\Competencia;
use App\Service\Reporting\ImportedTimesheetReport;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ImportedTimesheetReportTest extends TestCase
{
    public function testMonthIncludesEachImportedEntryWithClientAndProjectAndNoImplicitEndFilter(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, alias TEXT)');
        $db->executeStatement('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
        $db->executeStatement('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, customer_id INTEGER)');
        $db->executeStatement('CREATE TABLE timesheet (id INTEGER PRIMARY KEY, user_id INTEGER, project_id INTEGER, '
            . 'activity_id INTEGER, begin TEXT, end TEXT, duration INTEGER, description TEXT, billable INTEGER)');
        $db->insert('users', ['id' => 1, 'alias' => 'Cooperada']);
        $db->insert('customers', ['id' => 12, 'name' => 'Cliente A']);
        $db->insert('projects', ['id' => 7, 'name' => 'Projeto X', 'customer_id' => 12]);
        $base = [
            'user_id' => 1, 'project_id' => 7, 'activity_id' => 9,
            'description' => '<b>Não executar HTML</b>', 'billable' => 1,
        ];
        $db->insert('timesheet', ['id' => 81, ...$base,
            'begin' => '2026-09-01 00:00:00', 'end' => '2026-09-01 01:01:01', 'duration' => 3661]);
        // A record started in September is in the September import even when it ends in October.
        $db->insert('timesheet', ['id' => 82, ...$base,
            'begin' => '2026-09-30 23:45:00', 'end' => '2026-10-01 00:15:00', 'duration' => 1800]);
        $db->insert('timesheet', ['id' => 83, ...$base,
            'begin' => '2026-10-01 00:00:00', 'end' => null, 'duration' => null]);

        $report = new ImportedTimesheetReport($db);
        $rows = $report->rows(Competencia::fromString('2026-09'));
        self::assertCount(2, $rows);
        self::assertSame([81, 82], array_column($rows, 'apontamento_id'));
        self::assertSame('Cooperada', $rows[0]['nome']);
        self::assertSame('Cliente A', $rows[0]['cliente']);
        self::assertSame(12, $rows[0]['customer_id']);
        self::assertSame('Projeto X', $rows[0]['projeto']);
        self::assertSame(3661, $rows[0]['duracao_segundos']);
        self::assertSame('<b>Não executar HTML</b>', $rows[0]['descricao']);
        self::assertSame('2026-10-01 00:15:00', $rows[1]['fim']);
        self::assertSame(1800, $rows[1]['duracao_segundos']);
        self::assertArrayNotHasKey('tax_number', $rows[0]);
    }

    public function testUnlinkedCustomerIsNotSilentlyExcluded(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, alias TEXT)');
        $db->executeStatement('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
        $db->executeStatement('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, customer_id INTEGER)');
        $db->executeStatement('CREATE TABLE timesheet (id INTEGER PRIMARY KEY, user_id INTEGER, project_id INTEGER, '
            . 'activity_id INTEGER, begin TEXT, end TEXT, duration INTEGER, description TEXT, billable INTEGER)');
        $db->insert('timesheet', ['id' => 99, 'user_id' => 5, 'project_id' => 70, 'activity_id' => 4,
            'begin' => '2026-09-01 10:00:00', 'end' => null, 'duration' => null,
            'description' => null, 'billable' => 0]);
        $rows = new ImportedTimesheetReport($db)->rows(Competencia::fromString('2026-09'));
        self::assertCount(1, $rows);
        self::assertSame('Kimai #5', $rows[0]['nome']);
        self::assertSame('Cliente não vinculado', $rows[0]['cliente']);
        self::assertSame(0, $rows[0]['customer_id']);
        self::assertSame('Projeto #70', $rows[0]['projeto']);
        self::assertNull($rows[0]['duracao_segundos']);
        self::assertSame('', $rows[0]['fim']);
    }
}
