<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReportNavigationTest extends WebTestCase
{
    public function testHomeIsOnlyAnEntryPointToTheClosingWorkflow(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('/acoes/make-producao', $client->getResponse()->headers->get('Location'));
    }

    public function testSettingsBaseRedirectsToCanonicalMembersRoutePreservingFilters(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        self::assertSame('app_sourceconfiguration_index', $router->match('/configuracao')['_route']);
        self::assertSame('app_sourceconfiguration_members', $router->match('/configuracao/cooperados')['_route']);
        self::assertSame('app_systemconfiguration_index', $router->match('/configuracao/integracoes')['_route']);

        $client->request('GET', '/configuracao?competencia=2026-08&dtorder=0%3Aasc&dtname=Ana');
        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertSame('/configuracao/cooperados', (string) parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame('2026-08', $params['competencia']);
        self::assertSame('0:asc', $params['dtorder']);
        self::assertSame('Ana', $params['dtname']);
    }

    public function testInvoicesCanBeOpenedFromCatalogWithoutChoosingMonthFirst(): void
    {
        $client = static::createClient();
        $client->request('GET', '/relatorios/invoices');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Entradas e saídas', $html);
        self::assertStringContainsString('id="invoices-competence"', $html);
        self::assertStringContainsString('/api/v1/invoices?ano-mes=', $html);

        $client->request('GET', '/relatorios/faturas-conferencia?ano-mes=2026-09');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Faturas para conferência', $html);
        self::assertStringContainsString('data-pending-invoices="yes"', $html);
        self::assertStringContainsString('action="/relatorios/faturas-conferencia"', $html);
        self::assertStringContainsString('/api/v1/faturas-conferencia?ano-mes=2026-09', $html);
        self::assertStringNotContainsString('name="pendentes" value="1"', $html);
        self::assertStringContainsString('relatório próprio de faturas', $html);

        $client->request('GET', '/relatorios/faturas-conferencia');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/relatorios/invoices?ano-mes=2026-09&pendentes=1');
        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame(
            '/relatorios/faturas-conferencia?ano-mes=2026-09',
            $client->getResponse()->headers->get('Location'),
        );

        $client->request('GET', '/relatorios/invoices?pendentes=1');
        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith(
            '/relatorios/faturas-conferencia?ano-mes=',
            (string) $client->getResponse()->headers->get('Location'),
        );

        foreach (['/relatorios/invoices', '/relatorios/faturas-conferencia'] as $path) {
            $client->request('GET', $path . '?ano-mes=invalid');
            self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        }
    }

    public function testCanonicalReportsAreRoutedAndAvailableInDedicatedCatalog(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        foreach ([
            'calculos' => 'app_calculos_index',
            'categorias' => 'app_categorias_index',
            'invoices' => 'app_invoices_index',
            'faturas-conferencia' => 'app_invoices_pending',
            'producao' => 'app_producao_index',
            'capital-social' => 'app_capitalsocial_index',
            'capital-social-summarized' => 'app_capitalsocialsummarized_index',
            'trabalhado-summarized' => 'app_trabalhadosummarized_index',
        ] as $path => $route) {
            self::assertSame($route, $router->match('/relatorios/' . $path)['_route']);
        }
        self::assertSame('app_calculos_ajuda', $router->match('/relatorios/calculos/ajuda')['_route']);
        self::assertSame(
            'app_api_invoices_pending',
            $router->match('/api/v1/faturas-conferencia')['_route'],
        );

        $client->request('GET', '/relatorios');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('href="/relatorios/calculos"', $client->getResponse()->getContent());
        self::assertStringContainsString('href="/relatorios/producao"', $client->getResponse()->getContent());
        self::assertStringContainsString('href="/relatorios/faturas-conferencia"', $client->getResponse()->getContent());
        self::assertStringContainsString('Faturas para conferência', $client->getResponse()->getContent());
    }
}
