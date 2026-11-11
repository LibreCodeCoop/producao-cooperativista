<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Service\Kimai\Source\Timesheets;
use DateTime;
use PHPUnit\Framework\TestCase;

final class TimesheetsSnapshotTest extends TestCase
{
    public function testUnfilteredMonthIsFetchedOnceForRosterAndTimesheetImport(): void
    {
        $source = new class () extends Timesheets {
            public int $apiCalls = 0;

            public function __construct()
            {
            }

            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                ++$this->apiCalls;
                return [['id' => $this->apiCalls, 'user' => 5]];
            }
        };

        self::assertSame([['id' => 1, 'user' => 5]], $source->getFromApi(new DateTime('2026-09-01')));
        self::assertSame([['id' => 1, 'user' => 5]], $source->getFromApi(new DateTime('2026-09-30')));
        self::assertSame(1, $source->apiCalls);
        self::assertSame([['id' => 2, 'user' => 5]], $source->getFromApi(new DateTime('2026-09-01'), 5));
        self::assertSame([['id' => 3, 'user' => 5]], $source->getFromApi(new DateTime('2026-10-01')));
        self::assertSame(3, $source->apiCalls);
    }
}
