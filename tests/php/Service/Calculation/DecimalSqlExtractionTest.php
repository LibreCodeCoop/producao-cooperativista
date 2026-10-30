<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Verifies that the local MySQL runtime preserves decimal JSON item totals. */
final class DecimalSqlExtractionTest extends KernelTestCase
{
    public function testJsonMoneySumUsesExactDecimalExtraction(): void
    {
        static::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        $total = $connection->fetchOne(<<<'SQL'
            SELECT SUM(price)
            FROM JSON_TABLE(
                :document,
                '$.items.data[*]' COLUMNS (
                    price DECIMAL(30, 12) PATH '$.price'
                )
            ) x
            SQL, [
                'document' => '{"items":{"data":[{"price":0.10},{"price":0.20}]}}',
            ]);
        self::assertSame('0.300000000000', (string) $total);
    }
}
