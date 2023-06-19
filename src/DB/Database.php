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

namespace ProducaoCooperativista\DB;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\LoggerInterface;

class Database
{
    /**
     * @var Connection[]
     */
    private array $connection;
    public const DB_LOCAL = 'local';
    public const DB_AKAUNTING = 'akaunting';

    public function __construct(LoggerInterface $logger = null)
    {
        $config = new Configuration();
        $logMiddleware = new Middleware($logger);
        $config->setMiddlewares([$logMiddleware]);

        $this->connection[self::DB_LOCAL] = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASSWORD'],
            'host' => $_ENV['DB_HOST'],
            'driver' => $_ENV['DB_ADAPTER'],
        ], $config);

        $this->connection[self::DB_AKAUNTING] = DriverManager::getConnection([
            'dbname' => $_ENV['DB_AKAUNTING_NAME'],
            'user' => $_ENV['DB_AKAUNTING_USER'],
            'password' => $_ENV['DB_AKAUNTING_PASSWORD'],
            'host' => $_ENV['DB_AKAUNTING_HOST'],
            'driver' => $_ENV['DB_AKAUNTING_ADAPTER'],
        ], $config);
    }

    public function getConnection(string $place = self::DB_LOCAL): Connection
    {
        return $this->connection[$place];
    }
}