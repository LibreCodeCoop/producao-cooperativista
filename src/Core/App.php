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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class App
{
    /**
     * Is CLI
     */
    public static bool $CLI = false;
    public static string $root;
    private static Container $container;

    public static function init()
    {
        self::$CLI = PHP_SAPI === 'cli';
        self::$root = realpath(__DIR__ . '/../..');
        self::loadContainers();
    }

    private static function loadContainers(): void
    {
        $containerBuilder = new \DI\ContainerBuilder();
        $containerBuilder->addDefinitions([
            Logger::class => \DI\autowire()
                ->constructor('PRODUCAO_COOPERATIVISTA')
                ->method('pushHandler', new StreamHandler(self::$root . '/storage/logs/system.log')),
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
            \Twig\Loader\FilesystemLoader::class => \DI\autowire()
                ->constructor(self::$root . '/resources/view'),
            \Twig\Environment::class => \DI\factory(function (ContainerInterface $c) {
                $loader = $c->get(\Twig\Loader\FilesystemLoader::class);
                $cache = filter_var(getenv('APP_DEBUG', true), FILTER_VALIDATE_BOOLEAN)
                    ? false
                    : self::$root . '/storage/cache';
                return new \Twig\Environment($loader, [
                    'cache' => $cache,
                ]);
            }),
            Application::class => \DI\factory(function (ContainerInterface $c) {
                $application = new Application();

                $application->addCommands([
                    $c->get(GetCustomersCommand::class),
                    $c->get(GetInvoicesCommand::class),
                    $c->get(GetNfseCommand::class),
                    $c->get(GetProjectsCommand::class),
                    $c->get(GetTimesheetsCommand::class),
                    $c->get(GetTransactionsCommand::class),
                    $c->get(GetCategoriesCommand::class),
                    $c->get(GetTaxesCommand::class),
                    $c->get(GetUsersCommand::class),
                    $c->get(MakeProducaoCommand::class),
                ]);

                // Doctrine ORM
                DoctrineOrmConsoleRunner::addCommands($application, $c->get(SingleManagerProvider::class));

                // Doctrine Migrations
                $dependencyFactory = DoctrineMigrationsConsoleRunner::findDependencyFactory();
                DoctrineMigrationsConsoleRunner::addCommands($application, $dependencyFactory);
                return $application;
            }),
            App::class => \DI\autowire(),
            Request::class => \DI\factory(function () {
                return Request::createFromGlobals();
            }),
            RequestContext::class => \DI\factory(function () {
                $context = new RequestContext();
                $request = self::$container->get(Request::class);
                $context->fromRequest($request);
                return $context;
            }),
            UrlGenerator::class => \DI\factory(function (ContainerInterface $c) {
                return new UrlGenerator(
                    $c->get(App::class)->getRouteCollection(),
                    $c->get(Request::class)
                );
            }),
        ]);
        self::$container = $containerBuilder->build();
    }

    /**
     * Returns an entry of the container by its name.
     *
     * @template T
     * @param string|class-string<T> $id Entry name or a class name.
     *
     * @return mixed|T
    */
    public static function get(string $id): mixed
    {
        return self::$container->get($id);
    }

    public function getRouteCollection(): RouteCollection
    {
        $routes = new RouteCollection();
        $routesList = require self::$root . '/config/routes.php';
        foreach ($routesList as $route) {
            $name = $route['name'];
            [$controllerName, $methodName] = explode('#', $name);
            $controllerName = 'ProducaoCooperativista\Controller\\' . ucfirst($controllerName);

            $routes->add($name, new Route(
                $route['path'] ?? '',
                ['controller' => $controllerName, 'method' => $methodName],
                $route['requirements'] ?? [],
                $route['options'] ?? [],
                $route['host'] ?? null,
                $route['schemes'] ?? [],
                $route['methods'] ?? ['GET'],
                $route['condition'] ?? null
            ));
        }
        return $routes;
    }

    public function runHttp(): void
    {
        if (self::$CLI) {
            echo 'Warning: Should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL;
            return;
        }

        $routes = $this->getRouteCollection();

        $context = self::get(RequestContext::class);

        $matcher = new UrlMatcher($routes, $context);

        try {
            $parameters = $matcher->match($context->getPathInfo());

            $controller = self::get($parameters['controller']);

            $response = $controller->{$parameters['method']}();
        } catch (ResourceNotFoundException $e) {
            $response = new Response('404', Response::HTTP_NOT_FOUND);
        }

        $response->prepare(self::$container->get(Request::class));
        $response->send();
    }
}
