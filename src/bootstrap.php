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

use Doctrine\Migrations\Tools\Console\ConsoleRunner as DoctrineMigrationsConsoleRunner;
use Doctrine\ORM\Tools\Console\ConsoleRunner as DoctrineOrmConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ProducaoCooperativista\Command\GetCategoriesCommand;
use ProducaoCooperativista\Command\GetCustomersCommand;
use ProducaoCooperativista\Command\GetInvoicesCommand;
use ProducaoCooperativista\Command\GetNfseCommand;
use ProducaoCooperativista\Command\GetProjectsCommand;
use ProducaoCooperativista\Command\GetTaxesCommand;
use ProducaoCooperativista\Command\GetTimesheetsCommand;
use ProducaoCooperativista\Command\GetTransactionsCommand;
use ProducaoCooperativista\Command\GetUsersCommand;
use ProducaoCooperativista\Command\MakeProducaoCommand;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\Dates;
use ProducaoCooperativista\Service\Source\Nfse;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

$includeIfExists = function (string $file): bool {
    if (file_exists($file)) {
        include $file;
        return true;
    }
    return false;
};

if ((!$includeIfExists(__DIR__ . '/../vendor/autoload.php')) && (!$includeIfExists(__DIR__ . '/../../../autoload.php'))) {
    echo 'You must set up the project dependencies using `composer install`' . PHP_EOL .
        'See https://getcomposer.org/download/ for instructions on installing Composer' . PHP_EOL;
    exit(1);
}

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

error_reporting(-1);

$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions([
    Logger::class => \DI\autowire()
        ->constructor('PRODUCAO_COOPERATIVISTA')
        ->method('pushHandler', new StreamHandler('logs/system.log')),
    LoggerInterface::class => \DI\get(Logger::class),
    Database::class => \DI\autowire(),
    Dates::class => \DI\autowire()
        ->constructorParameter('locationHolydays', \DI\env('HOLYDAYS_LIST', 'br-national')),
    'ProducaoCooperativista\Service\Kimai\Source\*' => \DI\autowire('ProducaoCooperativista\Service\Kimai\Source\*'),
    'ProducaoCooperativista\Provider\Akaunting\*' => \DI\autowire('ProducaoCooperativista\Provider\Akaunting\*'),
    'ProducaoCooperativista\Service\Akaunting\Source\*' => \DI\autowire('ProducaoCooperativista\Service\Akaunting\Source\*'),
    Nfse::class => \DI\autowire(),
    'ProducaoCooperativista\Command\*Command' => \DI\autowire('ProducaoCooperativista\Command\*Command'),
    NumberFormatter::class => \DI\autowire()
        ->constructor(
            \DI\env('LOCALE', 'pt_BR'),
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
    $container->get(GetCategoriesCommand::class),
    $container->get(GetTaxesCommand::class),
    $container->get(GetUsersCommand::class),
    $container->get(MakeProducaoCommand::class),
]);

// Doctrine ORM
DoctrineOrmConsoleRunner::addCommands($application, $container->get(SingleManagerProvider::class));

// Doctrine Migrations
$dependencyFactory = DoctrineMigrationsConsoleRunner::findDependencyFactory();
DoctrineMigrationsConsoleRunner::addCommands($application, $dependencyFactory);
