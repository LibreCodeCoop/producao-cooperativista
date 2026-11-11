<?php

/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../../vendor/autoload.php';

if (!file_exists(__DIR__.'/../../.env')) {
    touch(__DIR__.'/../../.env');
}

$dotenv = new Dotenv();
$dotenv->usePutenv()->loadEnv(__DIR__.'/../../.env', overrideExistingVars: true);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';

// Fail BEFORE any destructive fixture or test method can run if either named
// connection accidentally targets an application or staging database.
$kernel = new \App\Kernel('test', true);
$kernel->boot();
try {
    $doctrine = $kernel->getContainer()->get('doctrine');
    foreach (['main', 'akaunting'] as $connectionName) {
        $database = (string) $doctrine->getConnection($connectionName)->getDatabase();
        \App\Service\Integration\TestConnectionGuard::assertDatabaseHost(
            (string) ($doctrine->getConnection($connectionName)->getParams()['host'] ?? ''),
            $connectionName,
        );
        if (!preg_match('/_test[A-Za-z0-9]*$/', $database)) {
            throw new \RuntimeException(sprintf(
                'Unsafe PHPUnit database on connection %s: %s. Tests require separate *_test databases.',
                $connectionName,
                $database
            ));
        }
    }
    $apiUrl = (string) getenv('AKAUNTING_API_BASE_URL');
    if ($apiUrl !== '') {
        \App\Service\Integration\TestConnectionGuard::assertHttpTarget($apiUrl);
    }
} finally {
    $kernel->shutdown();
}
