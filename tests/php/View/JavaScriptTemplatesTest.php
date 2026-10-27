<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class JavaScriptTemplatesTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
            ['autoescape' => 'html']
        );
    }

    public function testReportTemplateEscapesApiUrlAndFilterConfiguration(): void
    {
        $url = '/api/v1/invoices?ano-mes=2026-05&filter="><script>alert(1)</script>';
        $html = $this->twig()->render('layout/components/datatable.html.twig', [
            'url' => $url,
            'filterColumns' => ['metadata', 'a" onclick="malicious'],
        ]);

        self::assertStringContainsString('src="/js/report-table.js"', $html);
        self::assertStringContainsString('id="erros" role="alert"', $html);
        self::assertStringNotContainsString('$.ajax(', $html);
        self::assertStringNotContainsString('id="example" data-url="' . $url, $html);

        self::assertSame(1, preg_match('/data-url="([^"]+)"/', $html, $matches));
        self::assertSame($url, html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));
        self::assertSame(1, preg_match('/data-filter-columns="([^"]+)"/', $html, $matches));
        self::assertSame(
            ['metadata', 'a" onclick="malicious'],
            json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testCalculationFormulaUsesExternalScriptAndSafeHighlightAttribute(): void
    {
        $html = $this->twig()->render('calculos.index.html.twig', [
            'erros' => [],
            'data' => [
                'receita' => [
                    'label' => 'Receita',
                    'formula' => '<span class="receita">100</span>',
                    'color' => [10, 20, 30],
                    'font_color' => [245, 245, 245],
                ],
            ],
        ]);

        self::assertStringContainsString('src="/js/calculation-highlights.js"', $html);
        self::assertStringContainsString('class="label_receita" data-highlight-key="receita"', $html);
        self::assertStringContainsString('<span class="receita">100</span>', $html);
        self::assertSame(1, substr_count($html, 'code.jquery.com/jquery-3.7.1.min.js'));
        self::assertStringNotContainsString('toggleClass(', $html);
    }
}
