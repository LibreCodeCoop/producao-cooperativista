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

final class OperationalUiTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
            ['autoescape' => 'html']
        );
    }

    public function testHomePageUsesTheLocalStylesheetAndAccessibleNavigation(): void
    {
        $html = $this->twig()->render('index.index.html.twig', [
            'relatorios' => [
                ['label' => 'Cálculos', 'url' => '/calculos', 'description' => 'Bases da apuração'],
            ],
            'acoes' => [
                ['label' => 'Processar produção', 'url' => '/acoes/make-producao', 'description' => 'Prévia'],
            ],
        ]);

        self::assertStringContainsString('<html lang="pt-BR">', $html);
        self::assertStringContainsString('href="/css/app.css"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertStringContainsString('aria-labelledby="reports-heading"', $html);
        self::assertStringContainsString('Bases da apuração', $html);
        self::assertStringContainsString('Processar produção', $html);
        self::assertStringNotContainsString('<ul>\n        <li>', $html);
    }

    public function testThemeSelectorUsesSharedLayoutAndLoadsBeforePageStyles(): void
    {
        $html = $this->twig()->render('index.index.html.twig', [
            'relatorios' => [],
            'acoes' => [],
        ]);

        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        self::assertStringContainsString('src="/js/theme.js"', $html);
        self::assertStringContainsString('href="/css/theme.css"', $html);
        self::assertStringContainsString('id="theme-toggle"', $html);
        self::assertStringContainsString('id="theme-toggle-label"', $html);
        self::assertStringContainsString('id="theme-status"', $html);
        self::assertStringContainsString('id="theme-system" class="theme-system" hidden', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
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
        self::assertStringContainsString('PRODUCAO_STAGING_DATABASE', $html);
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

    public function testProductionJourneyShowsReviewLinksAndNoSecondPassword(): void
    {
        $html = $this->twig()->render('acoes/make_producao.html.twig', [
            'inicio_ano' => '2026', 'inicio_mes' => '08',
            'url' => '/acoes/do-make-producao',
            'baixar_dados' => 0, 'atualiza_producao' => 0, 'token' => 'csrf-token',
        ]);
        self::assertStringContainsString('href="/css/production-workflow.css"', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('name="baixar_dados" value="0" checked', $html);
        self::assertStringContainsString('name="atualiza_producao" value="0" checked', $html);
        self::assertStringContainsString('href="/producao?ano-mes=2026-08"', $html);
        self::assertStringContainsString('href="/calculos?ano-mes=2026-08"', $html);
        self::assertStringContainsString('href="/invoices?ano-mes=2026-08"', $html);
        self::assertStringContainsString('Apuração não é pagamento', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
        self::assertStringNotContainsString('PRODUCAO_ACTION_TOKEN', $html);
    }

    public function testPublicationModeIsExplicitAndHasReviewWarning(): void
    {
        $html = $this->twig()->render('acoes/make_producao.html.twig', [
            'inicio_ano' => '2026', 'inicio_mes' => '08',
            'url' => '/acoes/do-make-producao',
            'baixar_dados' => 0, 'atualiza_producao' => 1, 'token' => 'csrf-token',
        ]);
        self::assertStringContainsString('name="atualiza_producao" value="1" checked', $html);
        self::assertStringContainsString('id="publication-warning" role="note"', $html);
        self::assertStringContainsString('Publicar documentos no Akaunting', $html);
    }

    public function testAuditPagesKeepTheSelectedPeriodAndReturnPath(): void
    {
        foreach (['producao.index.html.twig', 'invoices.index.html.twig'] as $view) {
            $html = $this->twig()->render($view, [
                'competencia' => '2026-08',
                'url' => '/api/v1/producao?ano-mes=2026-08',
            ]);
            self::assertStringContainsString('name="ano-mes" value="2026-08"', $html);
            self::assertStringContainsString('href="/calculos?ano-mes=2026-08"', $html);
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
