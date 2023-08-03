#!/usr/bin/env php
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

if (PHP_SAPI !== 'cli') {
    echo 'Warning: Should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL;
}

require __DIR__.'/../src/bootstrap.php';

use Doctrine\Migrations\Tools\Console\ConsoleRunner as DoctrineMigrationsConsoleRunner;
use Doctrine\ORM\Tools\Console\ConsoleRunner as DoctrineOrmConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ProducaoCooperativista\Command\GetCustomersCommand;
use ProducaoCooperativista\Command\GetInvoicesCommand;
use ProducaoCooperativista\Command\GetNfseCommand;
use ProducaoCooperativista\Command\GetProjectsCommand;
use ProducaoCooperativista\Command\GetTimesheetsCommand;
use ProducaoCooperativista\Command\GetTransactionsCommand;
use ProducaoCooperativista\Command\GetUsersCommand;
use ProducaoCooperativista\Command\MakeProducaoCommand;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Provider\Akaunting\Request;
use ProducaoCooperativista\Provider\Akaunting\Dataset;
use ProducaoCooperativista\Provider\Akaunting\ParseText;
use ProducaoCooperativista\Service\Akaunting\Source\Documents;
use ProducaoCooperativista\Service\Akaunting\Source\Transactions;
use ProducaoCooperativista\Service\Kimai\Source\Customers;
use ProducaoCooperativista\Service\Kimai\Source\Projects;
use ProducaoCooperativista\Service\Kimai\Source\Timesheets;
use ProducaoCooperativista\Service\Kimai\Source\Users;
use ProducaoCooperativista\Service\Source\Nfse;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

error_reporting(-1);

$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions([
    Logger::class => \DI\autowire()
        ->constructor('PRODUCAO_COOPERATIVISTA')
        ->method('pushHandler', new StreamHandler('logs/system.log')),
    LoggerInterface::class => \DI\get(Logger::class),
    Database::class => \DI\autowire(),
    Customers::class => \DI\autowire(),
    ParseText::class => \DI\autowire(),
    Request::class => \DI\autowire(),
    Dataset::class => \DI\autowire(),
    Documents::class => \DI\autowire(),
    Nfse::class => \DI\autowire(),
    Projects::class => \DI\autowire(),
    Timesheets::class => \DI\autowire(),
    Transactions::class => \DI\autowire(),
    Users::class => \DI\autowire(),
    'ProducaoCooperativista\Command\*Command' => \DI\autowire('ProducaoCooperativista\Command\*Command'),
    NumberFormatter::class => \DI\autowire()
        ->constructor(
            \DI\env('LOCALE') ?? 'pt_BR',
            NumberFormatter::CURRENCY,
        ),
    SingleManagerProvider::class => DI\factory(function (ContainerInterface $c) {
        /** @var Database */
        $database = $c->get(Database::class);
        return new SingleManagerProvider($database->getEntityManager());
    }),
]);
$container = $containerBuilder->build();

$application = new Application();

$application->addCommands([
    $container->get(GetCustomersCommand::class),
    $container->get(GetInvoicesCommand::class),
    $container->get(GetNfseCommand::class),
    $container->get(GetProjectsCommand::class),
    $container->get(GetTimesheetsCommand::class),
    $container->get(GetTransactionsCommand::class),
    $container->get(GetUsersCommand::class),
    $container->get(MakeProducaoCommand::class),
]);

// Doctrine ORM
DoctrineOrmConsoleRunner::addCommands($application, $container->get(SingleManagerProvider::class));

// Doctrine Migrations
$dependencyFactory = DoctrineMigrationsConsoleRunner::findDependencyFactory();
DoctrineMigrationsConsoleRunner::addCommands($application, $dependencyFactory);

$application->run();
