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

namespace ProducaoCooperativista\Core;

use DI\Container;
use Doctrine\Migrations\Tools\Console\ConsoleRunner as DoctrineMigrationsConsoleRunner;
use Doctrine\ORM\Tools\Console\ConsoleRunner as DoctrineOrmConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use NumberFormatter;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class App
{
    /**
     * Is CLI
     */
    public bool $CLI = false;
    public string $root;
    public Container $container;

    public function __construct()
    {
        $this->CLI = PHP_SAPI === 'cli';
        $this->root = __DIR__ . '/../..';
        $this->loadContainers();
    }

    public function loadContainers(): void
    {
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
            SingleManagerProvider::class => \DI\factory(function (ContainerInterface $c) {
                /** @var Database */
                $database = $c->get(Database::class);
                return new SingleManagerProvider($database->getEntityManager());
            }),
        ]);
        $this->container = $containerBuilder->build();
    }

    public function runHttp(): void
    {
        if ($this->CLI) {
            echo 'Warning: Should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL;
            return;
        }

        $routes = new RouteCollection();
        $routesList = require $this->root . '/config/routes.php';
        foreach ($routesList as $route) {
            [$controllerName, $methodName] = explode('#', $route['name']);
            $controllerName = 'ProducaoCooperativista\Controller\\' . ucfirst($controllerName);
            $routes->add($route['name'], new Route(
                $route['url'],
                [$controllerName, $methodName]
            ));
        }

        $context = new RequestContext();
        $request = Request::createFromGlobals();
        $context->fromRequest($request);

        $matcher = new UrlMatcher($routes, $context);

        $parameters = $matcher->match($context->getPathInfo());

        $controller = $this->container->get($parameters[0]);

        $controller->request = $request;
        $response = $controller->{$parameters[1]}();

        $response->prepare($request);
        $response->send();
    }

    public function runCli(): void
    {
        $application = new Application();

        $application->addCommands([
            $this->container->get(GetCustomersCommand::class),
            $this->container->get(GetInvoicesCommand::class),
            $this->container->get(GetNfseCommand::class),
            $this->container->get(GetProjectsCommand::class),
            $this->container->get(GetTimesheetsCommand::class),
            $this->container->get(GetTransactionsCommand::class),
            $this->container->get(GetCategoriesCommand::class),
            $this->container->get(GetTaxesCommand::class),
            $this->container->get(GetUsersCommand::class),
            $this->container->get(MakeProducaoCommand::class),
        ]);
        
        // Doctrine ORM
        DoctrineOrmConsoleRunner::addCommands($application, $this->container->get(SingleManagerProvider::class));
        
        // Doctrine Migrations
        $dependencyFactory = DoctrineMigrationsConsoleRunner::findDependencyFactory();
        DoctrineMigrationsConsoleRunner::addCommands($application, $dependencyFactory);

        $application->run();
    }
}