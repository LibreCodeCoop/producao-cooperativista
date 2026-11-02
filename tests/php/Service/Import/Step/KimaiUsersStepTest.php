<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Step;

use App\Service\Import\Step\KimaiUsersStep;
use App\Service\Kimai\Source\Timesheets;
use App\Service\Kimai\Source\Users;
use DateTime;
use PHPUnit\Framework\TestCase;

final class KimaiUsersStepTest extends TestCase
{
    public function testImportsOnlyDistinctUsersReferencedByTheMonthlySnapshot(): void
    {
        $month = new DateTime('2026-09-01');
        $hours = $this->createMock(Timesheets::class);
        $hours->expects(self::once())->method('getFromApi')->with($month)->willReturn([
            ['user' => 5], ['user' => 1], ['user' => 5], ['user' => 19],
        ]);
        $users = $this->createMock(Users::class);
        $users->expects(self::once())->method('setRequiredUserIds')->with([5, 1, 19])->willReturnSelf();
        $users->expects(self::once())->method('saveList');
        new KimaiUsersStep($users, $hours)->synchronize($month);
    }

    public function testEmptyMonthImportsNoUnrelatedProfiles(): void
    {
        $hours = $this->createStub(Timesheets::class);
        $hours->method('getFromApi')->willReturn([]);
        $users = $this->createMock(Users::class);
        $users->expects(self::once())->method('setRequiredUserIds')->with([])->willReturnSelf();
        $users->expects(self::once())->method('saveList');
        new KimaiUsersStep($users, $hours)->synchronize(new DateTime('2026-09-01'));
    }

    public function testInvalidTimesheetOwnerBlocksImportBeforeAnyUsersAreWritten(): void
    {
        $hours = $this->createStub(Timesheets::class);
        $hours->method('getFromApi')->willReturn([['user' => 0]]);
        $users = $this->createMock(Users::class);
        $users->expects(self::never())->method('saveList');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('PROFILE_SOURCE');
        new KimaiUsersStep($users, $hours)->synchronize(new DateTime('2026-09-01'));
    }
}
