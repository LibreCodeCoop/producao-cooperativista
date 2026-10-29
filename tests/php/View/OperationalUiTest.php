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
        $html = $this->renderReset(false, false);

        self::assertStringContainsString('Operação indisponível nesta instalação', $html);
        self::assertStringContainsString('Não é a senha de acesso a este site', $html);
        self::assertStringContainsString('Não remove tabelas, migrações, logs', $html);
        self::assertStringContainsString('PRODUCAO_ACTION_TOKEN', $html);
        self::assertStringContainsString('PRODUCAO_STAGING_DATABASE', $html);
        self::assertSame(3, substr_count($html, 'disabled'));
    }

    public function testConfiguredResetPreservesCsrfAndServerSideRequirements(): void
    {
        $html = $this->renderReset(true, true);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('pattern="ZERAR"', $html);
        self::assertStringContainsString('name="action_token" required', $html);
        self::assertStringNotContainsString('type="submit" class="button button-danger" disabled', $html);
        self::assertStringNotContainsString('Operação indisponível nesta instalação', $html);
    }

    public function testResetResponseEscapesUntrustedContent(): void
    {
        $html = $this->renderReset(true, true, '<script>alert(1)</script>', true);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    private function renderReset(
        bool $operationConfigured,
        bool $stagingConfigured,
        string $response = '',
        bool $error = false
    ): string {
        return $this->twig()->render('acoes/zerar_banco_local.html.twig', [
            'operation_configured' => $operationConfigured,
            'staging_configured' => $stagingConfigured,
            'url' => '/acoes/zerar-banco-local',
            'token' => 'csrf-token',
            'response' => $response,
            'response_is_error' => $error,
        ]);
    }
}
