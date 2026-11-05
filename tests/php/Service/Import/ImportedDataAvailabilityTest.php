<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Domain\Competencia;
use App\Service\Import\ImportedDataAvailability;
use App\Service\Import\StagingSchema;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ImportedDataAvailabilityTest extends TestCase
{
    public function testAvailabilityDistinguishesEmptyPartialAndPopulatedWorkMonths(): void
    {
        // Isolated, in-memory database: no external Akaunting or ETL write.
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (StagingSchema::TABLES as $table) {
            $database->executeStatement($table === 'timesheet'
                ? 'CREATE TABLE timesheet (begin TEXT NOT NULL, user_id INTEGER NOT NULL)'
                : 'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }
        $availability = new ImportedDataAvailability($database);
        $august = Competencia::fromString('2026-08');
        $september = Competencia::fromString('2026-09');

        self::assertSame([
            'competence' => '2026-09',
            'has_work_data' => false,
            'has_staging_data' => false,
            'work_entries' => 0,
            'work_members' => 0,
            'latest_imported_competence' => null,
        ], $availability->forCompetence($september));

        // A partial import containing only categories must not enable review.
        $database->insert('categories', ['id' => 16]);
        $partial = $availability->forCompetence($september);
        self::assertFalse($partial['has_work_data']);
        self::assertTrue($partial['has_staging_data']);
        self::assertSame(0, $partial['work_entries']);

        $database->insert('timesheet', ['begin' => '2026-08-20 10:00:00', 'user_id' => 5]);
        $database->insert('timesheet', ['begin' => '2026-08-20 11:00:00', 'user_id' => 5]);
        $database->insert('timesheet', ['begin' => '2026-08-21 10:00:00', 'user_id' => 8]);
        $data = $availability->forCompetence($august);
        self::assertTrue($data['has_work_data']);
        self::assertSame(3, $data['work_entries']);
        self::assertSame(2, $data['work_members']);
        self::assertSame('2026-08', $data['latest_imported_competence']);

        $otherMonth = $availability->forCompetence($september);
        self::assertFalse($otherMonth['has_work_data']);
        self::assertTrue($otherMonth['has_staging_data']);
        self::assertSame('2026-08', $otherMonth['latest_imported_competence']);

        // The actual reset removes every staging table's rows.
        foreach (StagingSchema::TABLES as $table) {
            $database->executeStatement('DELETE FROM ' . $table);
        }
        self::assertFalse($availability->forCompetence($august)['has_work_data']);
        self::assertFalse($availability->forCompetence($august)['has_staging_data']);
        self::assertNull($availability->forCompetence($august)['latest_imported_competence']);
    }
}
