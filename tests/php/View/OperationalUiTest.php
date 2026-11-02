<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;
use App\Service\Config\BrandSettings;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OperationalUiTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
            ['autoescape' => 'html']
        );
    }

    public function testMainNavigationHasOneClosingItemAndConfigurableBrand(): void
    {
        $html = $this->twig()->render('index.relatorios.html.twig', ['relatorios' => []]);

        self::assertStringContainsString('<html lang="pt-BR">', $html);
        self::assertStringContainsString('href="/css/app.css"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertSame(1, preg_match_all('/class="header-nav-link"[^>]*>Fechamento<\/a>/', $html));
        self::assertStringContainsString('href="/relatorios"', $html);
        self::assertStringContainsString('href="/configuracao/cooperados"', $html);
        self::assertStringNotContainsString('>Visão geral</a>', $html);
        self::assertStringContainsString('src="https://librecode.coop/assets/images/logo/librecode.png"', $html);
    }

    public function testAllThreeBrandModesKeepHeaderAccessible(): void
    {
        $previous = getenv('APP_BRAND_DISPLAY_MODE');
        try {
            foreach ([
                'logo_and_text' => [true, true],
                'logo_only' => [true, false],
                'text_only' => [false, true],
            ] as $mode => [$showLogo, $showText]) {
                putenv('APP_BRAND_DISPLAY_MODE=' . $mode);
                $html = $this->twig()->render('index.relatorios.html.twig', [
                    'relatorios' => [],
                    'app_brand' => new BrandSettings(),
                ]);
                self::assertSame(1, preg_match('~<header class="app-header">(.*?)</header>~s', $html, $matches));
                $header = $matches[1];
                self::assertSame($showLogo, str_contains($header, 'class="brand-logo"'), $mode);
                self::assertSame($showText, str_contains($header, 'class="brand-text"'), $mode);
                self::assertStringContainsString(
                    'aria-label="LibreCode: abrir fechamento da produção cooperativista"',
                    $header,
                );
                self::assertStringContainsString('<title>Relatórios | LibreCode</title>', $html);
                self::assertStringContainsString('LibreCode · Produção cooperativista', $html);
                if ($mode === 'logo_only') {
                    self::assertStringContainsString('alt="LibreCode"', $header);
                    self::assertStringNotContainsString('<small>Produção cooperativista</small>', $header);
                }
            }
        } finally {
            putenv($previous === false ? 'APP_BRAND_DISPLAY_MODE' : 'APP_BRAND_DISPLAY_MODE=' . $previous);
        }
    }

    public function testReportsCatalogExposesTheFinancialReports(): void
    {
        $html = $this->twig()->render('index.relatorios.html.twig', [
            'relatorios' => [
                ['label' => 'Memória de cálculo', 'url' => '/relatorios/calculos', 'description' => 'Bases da apuração'],
            ],
        ]);
        self::assertStringContainsString('id="reports-heading"', $html);
        self::assertStringContainsString('href="/relatorios/calculos"', $html);
        self::assertStringContainsString('Bases da apuração', $html);
        self::assertStringContainsString('Memória de cálculo', $html);
    }

    public function testThemeSelectorUsesSharedLayoutAndLoadsBeforePageStyles(): void
    {
        $html = $this->twig()->render('index.relatorios.html.twig', ['relatorios' => []]);

        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        self::assertStringContainsString('src="/js/theme.js"', $html);
        self::assertStringContainsString('href="/css/theme.css"', $html);
        self::assertStringContainsString('id="theme-toggle"', $html);
        self::assertStringContainsString('id="theme-toggle-icon"', $html);
        self::assertStringNotContainsString('id="theme-toggle-label"', $html);
        self::assertStringNotContainsString('id="theme-status"', $html);
        self::assertStringNotContainsString('id="theme-system"', $html);
        self::assertStringContainsString('aria-label="Ativar tema escuro"', $html);
        self::assertStringContainsString('type="button" id="theme-toggle"', $html);
        self::assertLessThan(
            strpos($html, 'href="/css/app.css"'),
            strpos($html, 'src="/js/theme.js"'),
            'Theme must be applied before the first stylesheet to avoid a flash of the wrong colors.'
        );
        self::assertGreaterThan(
            strpos($html, 'href="/css/app.css"'),
            strpos($html, 'href="/css/theme.css"'),
            'Theme overrides must be loaded after the global stylesheet.'
        );
    }

    public function testResetPageExplainsItsScopeAndDisablesUnavailableOperation(): void
    {
        $html = $this->renderReset(false);

        self::assertStringContainsString('Operação indisponível nesta instalação', $html);
        self::assertStringContainsString('Não remove tabelas, migrações, logs', $html);
        self::assertStringNotContainsString('PRODUCAO_ACTION_TOKEN', $html);
        self::assertStringContainsString('Ambiente e fontes', $html);
        self::assertStringContainsString('Ambiente de execução', $html);
        self::assertStringNotContainsString('Orientações para a administração do servidor', $html);
        self::assertSame(2, substr_count($html, 'disabled'));
    }

    public function testConfiguredResetPreservesCsrfAndServerSideRequirements(): void
    {
        $html = $this->renderReset(true);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('pattern="ZERAR"', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
        self::assertStringNotContainsString('type="submit" class="button button-danger" disabled', $html);
        self::assertStringNotContainsString('Operação indisponível nesta instalação', $html);
    }

    public function testResetResponseEscapesUntrustedContent(): void
    {
        $html = $this->renderReset(true, '<script>alert(1)</script>', true);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testProductionWizardCombinesFinancialReviewAndPublicationInOneStep(): void
    {
        $html = $this->twig()->render('acoes/make_producao.html.twig', [
            'inicio_ano' => '2026', 'inicio_mes' => '08',
            'url' => '/acoes/do-make-producao', 'token' => 'csrf-token',
        ]);
        self::assertStringContainsString('href="/css/production-workflow.css"', $html);
        self::assertStringContainsString('src="/js/production-workflow.js"', $html);
        self::assertStringContainsString('src="/js/report-table.js"', $html);
        self::assertStringContainsString('href="/css/data-grid.css"', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('name="operation" value="import"', $html);
        self::assertStringContainsString('data-workflow-step="1"', $html);
        self::assertStringContainsString('data-step-indicator="1"', $html);
        self::assertStringContainsString('data-step-indicator="2" disabled', $html);
        self::assertStringContainsString('data-workflow-step="2" hidden', $html);
        self::assertStringNotContainsString('data-workflow-step="3"', $html);
        self::assertStringNotContainsString('data-workflow-step="4"', $html);
        self::assertStringContainsString('data-financial-review="yes"', $html);
        self::assertStringContainsString('data-production-mode="yes"', $html);
        self::assertStringNotContainsString('id="reload-preview"', $html);
        self::assertStringNotContainsString('data-inline-weight="yes"', $html);
        self::assertStringNotContainsString('src="/js/settings-inline-weight.js"', $html);
        self::assertStringContainsString('data-manual-init="yes"', $html);
        self::assertStringContainsString('id="example"', $html);
        self::assertStringContainsString('href="/relatorios/calculos?ano-mes=2026-08"', $html);
        self::assertStringContainsString('href="/relatorios/invoices?ano-mes=2026-08"', $html);
        self::assertStringContainsString('Não publicar agora', $html);
        self::assertStringContainsString('id="publication-confirmation"', $html);
        self::assertStringContainsString('id="submit"', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
        self::assertLessThan(
            strpos($html, 'id="example"'),
            strpos($html, '</form>'),
            'The financial publication form must end before the review table.'
        );
    }

    public function testProductionReviewAssetsAreVersionedToAvoidStaleBrowserScripts(): void
    {
        $html = $this->twig()->render('acoes/make_producao.html.twig', [
            'inicio_ano' => '2026', 'inicio_mes' => '09',
            'url' => '/acoes/do-make-producao', 'token' => 'csrf-token',
            'workflow_asset_version' => 'a1b2c3d4e5f6',
        ]);

        self::assertStringContainsString(
            'src="/js/production-workflow.js?v=a1b2c3d4e5f6"',
            $html
        );
        self::assertStringContainsString(
            'href="/css/production-workflow.css?v=a1b2c3d4e5f6"',
            $html
        );
        self::assertStringContainsString('src="/js/report-table.js?v=a1b2c3d4e5f6"', $html);
        self::assertStringContainsString('id="calculate-financial-preview"', $html);
        self::assertStringContainsString('data-sources-url="/api/v1/producao/fontes"', $html);
    }

    public function testAuditPagesKeepTheSelectedPeriodAndReturnPath(): void
    {
        foreach (['producao.index.html.twig', 'invoices.index.html.twig'] as $view) {
            $html = $this->twig()->render($view, [
                'competencia' => '2026-08',
                'url' => '/api/v1/producao?ano-mes=2026-08',
                'productionMode' => true,
            ]);
            self::assertStringContainsString('name="ano-mes" value="2026-08"', $html);
            if ($view === 'producao.index.html.twig') {
                self::assertStringContainsString('data-production-mode="yes"', $html);
                self::assertStringNotContainsString('src="/js/settings-inline-weight.js"', $html);
            }
            self::assertStringContainsString('href="/relatorios/calculos?ano-mes=2026-08"', $html);
            self::assertStringContainsString(
                'href="/acoes/make-producao?ano-mes=2026-08"',
                $html
            );
        }
    }

    private function renderReset(
        bool $stagingConfigured,
        string $response = '',
        bool $error = false
    ): string {
        return $this->twig()->render('acoes/zerar_banco_local.html.twig', [
            'staging_configured' => $stagingConfigured,
            'url' => '/acoes/zerar-banco-local',
            'token' => 'csrf-token',
            'response' => $response,
            'response_is_error' => $error,
        ]);
    }
}
