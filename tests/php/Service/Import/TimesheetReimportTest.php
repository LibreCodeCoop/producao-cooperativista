<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Kimai\Source\Timesheets;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimesheetReimportTest extends KernelTestCase
{
    public function testUpdatedAndDeletedKimaiHoursAreReconciledWithoutResettingOtherMonths(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get('doctrine.dbal.main_connection');
        $source = static::getContainer()->get(Timesheets::class);

        $base = [
            'activity_id' => 1,
            'project_id' => 2,
            'user_id' => 3,
            'begin' => '2027-11-12 08:00:00',
            'end' => '2027-11-12 09:00:00',
            'duration' => 3600,
            'description' => 'Old',
            'rate' => 250.0,
            'internal_rate' => 100.0,
            'exported' => 0,
            'billable' => 1,
        ];
        foreach ([998001, 998002] as $id) {
            $connection->insert('timesheet', ['id' => $id, ...$base]);
        }
        $connection->insert('timesheet', [
            'id' => 998003,
            ...array_replace($base, ['begin' => '2027-12-12 08:00:00', 'end' => '2027-12-12 09:00:00']),
        ]);

        try {
            $removed = $source->syncMonth(new DateTime('2027-11-01'), [
                [
                    'id' => 998001,
                    'activity' => 1,
                    'project' => 2,
                    'user' => 3,
                    'begin' => '2027-11-12T08:00:00+0000',
                    'end' => '2027-11-12T10:00:00+0000',
                    'duration' => 7200,
                    'description' => 'Changed in Kimai',
                    'rate' => 250.0,
                    'internalRate' => 100.0,
                    'exported' => false,
                    'billable' => true,
                ],
            ]);

            self::assertSame(1, $removed);
            self::assertEqualsWithDelta(7200.0, (float) $connection->fetchOne(
                'SELECT duration FROM timesheet WHERE id = 998001'
            ), 0.001);
            self::assertFalse($connection->fetchOne('SELECT id FROM timesheet WHERE id = 998002'));
            self::assertSame(998003, (int) $connection->fetchOne('SELECT id FROM timesheet WHERE id = 998003'));
        } finally {
            $connection->executeStatement('DELETE FROM timesheet WHERE id IN (998001, 998002, 998003)');
        }
    }
}
