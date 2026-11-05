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

    public function testClosingOnlyOffersReviewAndResetWhenStagingHasData(): void
    {
        $context = [
            'inicio_ano' => '2026',
            'inicio_mes' => '09',
            'token' => 'test-token',
            'url' => '/acoes/do-make-producao',
            'imported_competence' => null,
            'workflow_asset_version' => 'cafebabe1234',
        ];
        $empty = $this->twig()->render('acoes/make_producao.html.twig', $context + [
            'import_availability' => [
                'competence' => '2026-09', 'has_work_data' => false,
                'has_staging_data' => false, 'work_entries' => 0, 'work_members' => 0,
            ],
        ]);
        self::assertStringContainsString('Base importada vazia', $empty);
        self::assertMatchesRegularExpression('~id="use-existing"[^>]*hidden disabled~s', $empty);
        self::assertMatchesRegularExpression('~id="reset-staging-link"[^>]*hidden~s', $empty);
        self::assertStringContainsString('data-imported-work="no"', $empty);
        self::assertStringContainsString('data-staging-data="no"', $empty);
        self::assertStringContainsString('data-availability-url="/api/v1/producao/disponibilidade"', $empty);
        self::assertStringContainsString('Importar competência', $empty);

        $partial = $this->twig()->render('acoes/make_producao.html.twig', $context + [
            'import_availability' => [
                'competence' => '2026-09', 'has_work_data' => false,
                'has_staging_data' => true, 'work_entries' => 0, 'work_members' => 0,
            ],
        ]);
        self::assertStringContainsString('Sem apontamentos desta competência', $partial);
        self::assertMatchesRegularExpression('~id="use-existing"[^>]*hidden disabled~s', $partial);
        self::assertDoesNotMatchRegularExpression('~id="reset-staging-link"[^>]*hidden~s', $partial);

        $available = $this->twig()->render('acoes/make_producao.html.twig', $context + [
            'import_availability' => [
                'competence' => '2026-09', 'has_work_data' => true,
                'has_staging_data' => true, 'work_entries' => 214, 'work_members' => 7,
            ],
        ]);
        self::assertStringContainsString('214 apontamentos de 7 cooperados', $available);
        self::assertDoesNotMatchRegularExpression('~id="use-existing"[^>]*hidden~s', $available);
        self::assertDoesNotMatchRegularExpression('~id="reset-staging-link"[^>]*hidden~s', $available);
        self::assertStringContainsString('data-imported-work="yes"', $available);
    }

    public function testCapitalSummaryExplainsPreliminaryBalanceAndHidesLinkTargetColumn(): void
    {
        $html = $this->twig()->render('capitalSocialSummarized.index.html.twig', [
            'url' => '/api/v1/capital-social-summarized',
            'workflow_asset_version' => 'cafe1234beef',
        ]);
        self::assertStringContainsString('data-capital-social-summary="yes"', $html);
        self::assertStringContainsString('nome_url', $html);
        self::assertStringContainsString('Saldo identificado no Akaunting', $html);
        self::assertStringContainsString('saldo não conciliado', $html);
        self::assertStringContainsString('não registra nem concede uma aprovação', $html);
        self::assertStringContainsString('livro ou ficha de capital', $html);
        self::assertStringContainsString('Razão contábil e balancete', $html);
        self::assertStringContainsString('/js/report-table.js?v=cafe1234beef', $html);
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

    public function testProductionStepsAreNormalFlowNotSticky(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/scss/production-workflow.scss',
        );
        self::assertSame(1, preg_match('/\.production-steps\s*\{([^}]*)\}/s', $source, $matches));
        self::assertStringNotContainsString('position:sticky', $matches[1]);
        self::assertStringNotContainsString('position:fixed', $matches[1]);
        self::assertStringNotContainsString('top:0', $matches[1]);
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
            'src="/js/production-progress.js?v=a1b2c3d4e5f6"',
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

    public function testPendingInvoicesReportProvidesStatusAndDirectLinksWithoutFinancialCalculation(): void
    {
        $html = $this->twig()->render('invoices.index.html.twig', [
            'competencia' => '2026-09',
            'financial_month' => '2026-10',
            'url' => '/api/v1/faturas-conferencia?ano-mes=2026-09',
            'pendentes' => true,
            'workflow_asset_version' => 'a1b2c3d4e5f6',
        ]);
        self::assertStringContainsString('Faturas para conferência', $html);
        self::assertStringContainsString('data-pending-invoices="yes"', $html);
        self::assertStringContainsString('action="/relatorios/faturas-conferencia"', $html);
        self::assertStringNotContainsString('name="pendentes" value="1"', $html);
        self::assertStringContainsString('id="pending-invoice-summary"', $html);
        self::assertStringContainsString('src="/js/report-table.js?v=a1b2c3d4e5f6"', $html);
        self::assertStringContainsString('/api/v1/faturas-conferencia?ano-mes=2026-09', $html);
        self::assertStringContainsString('/relatorios/invoices?ano-mes=2026-09', $html);
        self::assertStringContainsString('Situação da fatura', $html);
        self::assertStringContainsString('Recebimento registrado', $html);
        self::assertStringContainsString('não consulta o extrato bancário em tempo real', $html);
    }

    public function testForecastMemoryDisplaysUnpaidInvoiceStatusesNextToCalculatedAmounts(): void
    {
        $html = $this->twig()->render('calculos.index.html.twig', [
            'competencia' => '2026-09',
            'financial_month' => '2026-10',
            'source_review' => [
                'financial_month' => '2026-10',
                'unconfirmed_invoices' => [
                    'count' => 2,
                    'amount' => 375.00,
                    'documents' => [
                        ['id' => 731, 'number' => 'INV-731', 'amount' => 150.00,
                            'received_amount' => 0.00, 'status' => 'draft'],
                        ['id' => 732, 'number' => 'INV-732', 'amount' => 225.00,
                            'received_amount' => 50.00, 'status' => 'sent'],
                    ],
                ],
            ],
            'simulation' => false,
            'data' => [
                'total_notas_clientes' => [
                    'label' => 'Receita prevista',
                    'valor' => 375.00,
                    'formula' => '150 + 225',
                    'color' => [240, 240, 240],
                    'font_color' => [20, 20, 20],
                ],
            ],
            'erros' => [],
        ]);

        self::assertStringContainsString('Previsão, não autorização de pagamento', $html);
        self::assertStringContainsString('Faturas que precisam de conferência antes do pagamento', $html);
        self::assertStringContainsString('INV-731', $html);
        self::assertStringContainsString('INV-732', $html);
        self::assertStringContainsString('Rascunho', $html);
        self::assertStringContainsString('Enviada', $html);
        self::assertStringContainsString('Receitas previstas de clientes', $html);
        self::assertStringContainsString('Conferir todas as faturas', $html);
        self::assertStringNotContainsString('Não foi possível concluir a apuração', $html);
    }

    public function testForecastShowsUnclassifiedIncomeWithoutSuppressingCalculation(): void
    {
        $html = $this->twig()->render('calculos.index.html.twig', [
            'competencia' => '2026-08',
            'financial_month' => '2026-09',
            'simulation' => false,
            'source_review' => null,
            'unclassified_income' => [
                'count' => 1,
                'amount' => 881.21,
                'records' => [
                    [
                        'origin' => 'transaction',
                        'id' => 3470,
                        'amount' => 881.21,
                        'category' => 'Cliente > Externo > Recorrência',
                    ],
                ],
            ],
            'data' => [
                'total_notas_clientes' => [
                    'label' => 'Receitas previstas de clientes',
                    'valor' => 43665.16,
                    'formula' => '43665.16',
                    'color' => [240, 240, 240],
                    'font_color' => [20, 20, 20],
                ],
            ],
            'erros' => [],
        ]);

        self::assertStringContainsString('id="unclassified-income"', $html);
        self::assertStringContainsString('a memória é parcial', $html);
        self::assertStringContainsString('R$ 881,21', $html);
        self::assertStringContainsString('Lançamento transaction #3470', $html);
        self::assertStringContainsString('ano-mes=2026-08&amp;id=3470', $html);
        self::assertStringContainsString('R$ 43.665,16', $html);
        self::assertStringNotContainsString('Não foi possível concluir a apuração', $html);
        self::assertStringContainsString('A publicação permanece bloqueada', $html);
    }

    public function testReportsExposeSourceReconciliationWithoutPretendingToCalculatePayouts(): void
    {
        $source = [
            'competence' => '2026-09',
            'financial_month' => '2026-10',
            'hours' => ['members' => 7, 'entries' => 214],
            'income_transactions' => ['amount' => 0.0],
            'client_cost_transactions' => ['amount' => 2007.63],
            'internal_expense_transactions' => ['amount' => 11818.04],
            'unconfirmed_invoices' => ['count' => 7, 'amount' => 78279.48],
        ];
        $html = $this->twig()->render('calculos.index.html.twig', [
            'competencia' => '2026-09',
            'financial_month' => '2026-10',
            'source_review' => $source,
            'simulation' => false,
            'data' => [],
            'erros' => ['A base econômica calculada não permite a distribuição.'],
        ]);
        self::assertStringContainsString('name="ano-mes" value="2026-09"', $html);
        self::assertStringContainsString('Competência do trabalho: 2026-09', $html);
        self::assertStringContainsString('Mês financeiro das movimentações:', $html);
        self::assertStringContainsString('2026-10', $html);
        self::assertStringContainsString('Fontes importadas disponíveis para conferência', $html);
        self::assertStringContainsString('Dados importados do mês financeiro', $html);
        self::assertStringContainsString('diferença preliminar de R$ -2.007,63', $html);
        self::assertStringContainsString('Despesas internas identificadas: R$ 11.818,04', $html);
        self::assertStringContainsString('Ver fontes e vínculos para conciliação', $html);
        self::assertStringContainsString('não constitui base aprovada para distribuição ou pagamento', $html);
        self::assertStringContainsString('214', $html);
        self::assertStringContainsString('78.279,48', $html);
        self::assertStringContainsString('A apurar', $html);
        self::assertStringContainsString('/relatorios/faturas-conferencia?ano-mes=2026-09', $html);

        $production = $this->twig()->render('producao.index.html.twig', [
            'competencia' => '2026-09',
            'url' => '/api/v1/producao?ano-mes=2026-09',
            'sourceReviewUrl' => '/api/v1/producao/fontes?ano-mes=2026-09',
            'productionMode' => true,
            'kimai_timesheet_url' => 'https://kimai.librecode.coop/en/timesheet/',
        ]);
        self::assertStringContainsString('data-source-url="/api/v1/producao/fontes?ano-mes=2026-09"', $production);
        self::assertStringContainsString('id="production-report-status"', $production);
        self::assertStringContainsString('href="https://kimai.librecode.coop/en/timesheet/"', $production);
        self::assertStringContainsString('/relatorios/trabalhado-summarized?ano-mes=2026-09', $production);

        $invoices = $this->twig()->render('invoices.index.html.twig', [
            'competencia' => '2026-09',
            'url' => '/api/v1/invoices?ano-mes=2026-09',
            'pendentes' => false,
        ]);
        self::assertStringContainsString('data-financial-movements="yes"', $invoices);
        self::assertStringContainsString('contact_url', $invoices);
        self::assertStringContainsString('category_url', $invoices);
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
