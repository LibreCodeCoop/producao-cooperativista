<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Prevent PHPUnit fixture cleanup from deleting imported or financial data.
 * Both named Doctrine connections must be isolated, not just the last one.
 */
final class IsolatedTestDatabaseConfigurationTest extends KernelTestCase
{
    public function testMainAndAccountingConnectionsNeverPointToRegularSchemas(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ManagerRegistry::class);

        foreach (['main', 'akaunting'] as $name) {
            $database = (string) $registry->getConnection($name)->fetchOne('SELECT DATABASE()');
            self::assertMatchesRegularExpression(
                '/_test[A-Za-z0-9]*$/',
                $database,
                sprintf(
                    'The %s connection points at %s; PHPUnit must never run destructive fixtures there.',
                    $name,
                    $database
                )
            );
        }
    }
}
