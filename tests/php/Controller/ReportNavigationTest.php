<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReportNavigationTest extends WebTestCase
{
    public function testCanonicalReportsAreRoutedAndAvailableInDedicatedCatalog(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        foreach ([
            'calculos' => 'app_calculos_index',
            'categorias' => 'app_categorias_index',
            'invoices' => 'app_invoices_index',
            'producao' => 'app_producao_index',
            'capital-social' => 'app_capitalsocial_index',
            'capital-social-summarized' => 'app_capitalsocialsummarized_index',
            'trabalhado-summarized' => 'app_trabalhadosummarized_index',
        ] as $path => $route) {
            self::assertSame($route, $router->match('/relatorios/' . $path)['_route']);
        }
        self::assertSame('app_calculos_ajuda', $router->match('/relatorios/calculos/ajuda')['_route']);

        $client->request('GET', '/relatorios');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('href="/relatorios/calculos"', $client->getResponse()->getContent());
        self::assertStringContainsString('href="/relatorios/producao"', $client->getResponse()->getContent());
    }
}
