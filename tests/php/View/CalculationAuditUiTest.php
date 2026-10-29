<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\View;

use App\Helper\Dates;
use App\Service\Calculation\CustomerCostAllocator;
use App\Service\Movimentacao;
use App\Service\ProducaoCooperativista;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CalculationAuditUiTest extends TestCase
{
    public function testReportRetainsTraceAndExplainsAccountingBoundaries(): void
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
            ['autoescape' => 'html'],
        );
        $html = $twig->render('calculos.index.html.twig', [
            'erros' => [],
            'competencia' => '2026-08',
            'simulation' => false,
            'data' => [
                'total_notas_clientes' => [
                    'label' => 'Total notas clientes',
                    'formula' => '<span class="base_producao">100</span>',
                    'valor' => 100,
                    'color' => [10, 20, 30],
                    'font_color' => [255, 255, 255],
                ],
            ],
        ]);

        self::assertStringContainsString('rel="stylesheet" href="/css/calculation-report.css"', $html);
        self::assertStringContainsString('data-highlight-key="total_notas_clientes"', $html);
        self::assertStringContainsString('href="/calculos/ajuda#total_notas_clientes"', $html);
        self::assertStringContainsString('data-help=', $html);
        self::assertStringContainsString('href="/configuracao"', $html);
        self::assertStringContainsString('R$ 100,00', $html);
        self::assertStringContainsString('Fundo de Reserva', $html);
        self::assertStringContainsString('2. Custos e base móvel', $html);
        self::assertStringNotContainsString('Simulação com parâmetros alterados', $html);

        $simulated = $twig->render('calculos.index.html.twig', [
            'erros' => [],
            'competencia' => '2026-08',
            'simulation' => true,
            'data' => [],
        ]);
        self::assertStringContainsString('Simulação com parâmetros alterados na URL', $simulated);
    }

    public function testFormulaReferencesEscapeUntrustedValues(): void
    {
        $class = new ReflectionClass(ProducaoCooperativista::class);
        $object = $class->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($object, 'formatData');
        $data = [
            'origem' => ['valor' => '<img src=x onerror=alert(1)>'],
            'resultado' => [
                'valor' => 100,
                'formula' => '{resultado} = {origem} <a href="/ok" target="_blank">Auditar</a>',
            ],
        ];

        $formatted = $method->invoke($object, $data);
        self::assertStringNotContainsString('<img', $formatted['resultado']['formula']);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $formatted['resultado']['formula']);
        self::assertStringContainsString('rel="noopener noreferrer"', $formatted['resultado']['formula']);
        self::assertStringContainsString('class="origem"', $formatted['resultado']['formula']);
    }

    public function testDisplayedPerInvoiceCostMatchesAllocator(): void
    {
        $movement = $this->createStub(Movimentacao::class);
        $invoices = [
            10 => ['table' => 'invoice_transaction', 'id' => 10, 'amount' => 100.0, 'customer_reference' => 'sample', 'contact_name' => 'Cliente " <script>', 'percentual_desconto_fixo' => false],
            20 => ['table' => 'invoice_transaction', 'id' => 20, 'amount' => 300.0, 'customer_reference' => 'sample', 'contact_name' => 'Cliente', 'percentual_desconto_fixo' => false],
        ];
        $movement->method('getEntradasClientes')->willReturn($invoices);
        $movement->method('getCustosPorCliente')->willReturn(['sample' => 80.0]);
        $dates = new Dates();
        $dates->setInicio(new \DateTime('2026-08-01'));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/invoices');

        $class = new ReflectionClass(ProducaoCooperativista::class);
        $production = $class->newInstanceWithoutConstructor();
        foreach ([
            'movimentacao' => $movement,
            'dates' => $dates,
            'urlGenerator' => $urls,
            'customerCostAllocator' => new CustomerCostAllocator(),
        ] as $name => $value) {
            $class->getProperty($name)->setValue($production, $value);
        }
        $result = (new ReflectionMethod($production, 'getFormulaBaseProducao'))->invoke($production);
        self::assertStringContainsString(' - 20)', $result);
        self::assertStringContainsString(' - 60)', $result);
        self::assertStringNotContainsString(' - 80)', $result);
        self::assertStringContainsString('&quot; &lt;script&gt;', $result);
        self::assertStringNotContainsString('title="Cliente " <script>"', $result);
    }

    public function testTechnicalHelpReferencesOfficialLawAndAvoidsConflationOfFunds(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $html = $twig->render('calculos.ajuda.html.twig');
        self::assertStringContainsString('Lei 5.764/1971', $html);
        self::assertStringContainsString('Lei 12.690/2012', $html);
        self::assertStringContainsString('não é automaticamente', $html);
        self::assertStringContainsString('docs/calculation-audit.md', $html);
    }
}
