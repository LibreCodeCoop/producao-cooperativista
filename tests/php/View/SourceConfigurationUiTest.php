<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SourceConfigurationUiTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
    }

    public function testSettingsAreAvailableWithoutSecondPassword(): void
    {
        $html = $this->twig()->render('configuracao.index.html.twig', [
            'token' => 'csrf-value',
            'month' => '2026-08',
            'users' => [],
            'member' => null,
            'services' => [],
            'error' => '',
            'notifications' => [],
        ]);

        self::assertStringContainsString('<h1>Configuração</h1>', $html);
        self::assertStringContainsString('Cooperados e vínculos', $html);
        self::assertStringContainsString('href="/configuracao/integracoes"', $html);
        self::assertStringContainsString('href="/configuracao/cooperados"', $html);
        self::assertStringContainsString('action="/configuracao/cooperados"', $html);
        self::assertStringNotContainsString('Desbloquear administração', $html);
        self::assertStringNotContainsString('PRODUCAO_ACTION_TOKEN', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
        self::assertStringNotContainsString('/configuracao/desbloquear', $html);
        self::assertStringNotContainsString('/configuracao/bloquear', $html);
    }

    public function testConfigurationRosterUsesTheSameDataTablesAssetsAndFilteringAsReports(): void
    {
        $html = $this->twig()->render('configuracao.index.html.twig', [
            'token' => 'csrf-value',
            'month' => '2026-08',
            'users' => [
                [
                    'id' => 12, 'username' => 'ativa', 'alias' => 'Cooperada ativa',
                    'hours' => 8.5, 'participates' => true, 'system' => false, 'enabled' => true, 'imported_weight' => '1.25',
                ],
                [
                    'id' => 13, 'username' => 'desativada', 'alias' => 'Cooperada desativada',
                    'hours' => 0.0, 'participates' => false, 'system' => false, 'enabled' => false, 'imported_weight' => null,
                ],
            ],
            'member' => null,
            'services' => [],
            'error' => '',
            'notifications' => [],
        ]);

        self::assertStringContainsString('cdn.datatables.net/2.1.8', $html);
        self::assertStringContainsString('cdn.datatables.net/buttons/3.1.2', $html);
        self::assertStringContainsString('id="settings-members"', $html);
        self::assertStringContainsString('href="/configuracao/cooperado/12/editar?competencia=2026-08"', $html);
        self::assertStringContainsString('data-member-editor-open data-settings-member-link>Cooperada ativa</a>', $html);
        self::assertStringNotContainsString('<th scope="col">Ação</th>', $html);
        self::assertStringContainsString('src="/js/settings-panel.js"', $html);
        self::assertStringContainsString('id="settings-status-filter"', $html);
        self::assertStringContainsString('value="Ativo" selected', $html);
        self::assertStringContainsString('value="all">Todos</option>', $html);
        self::assertStringContainsString('<th scope="col">Peso</th>', $html);
        self::assertStringNotContainsString('data-weight-url=', $html);
        self::assertStringContainsString('title="Peso da última importação"', $html);
        self::assertStringContainsString('1,25', $html);
        self::assertStringContainsString('Não importado', $html);
        self::assertStringContainsString('data-order="-1"', $html);
        self::assertStringContainsString('data-order="1.25"', $html);
        self::assertStringNotContainsString('data-weight-form', $html);
        self::assertStringNotContainsString('name="field" value="weight"', $html);
        self::assertStringNotContainsString('src="/js/settings-inline-weight.js"', $html);
        self::assertStringContainsString('id="settings-hours-filter"', $html);
        self::assertStringContainsString('id="settings-name-filter"', $html);
        self::assertStringContainsString('id="settings-user-filter"', $html);
        self::assertStringContainsString('id="settings-clear-filters"', $html);
        self::assertStringContainsString('id="settings-default-filters"', $html);
        self::assertStringContainsString('data-order="8.5"', $html);
        self::assertStringContainsString('Cooperada desativada', $html);
        self::assertStringContainsString('<td>Desativado</td>', $html);
        self::assertStringNotContainsString('id="member-search"', $html);
        self::assertStringNotContainsString('id="example"', $html);
        self::assertStringNotContainsString('src="/js/report-table.js"', $html);
    }

    public function testSourceEditorHasOneFiscalFieldAndHidesTechnicalIdentifiersFromReaders(): void
    {
        $data = [
            'token' => 'csrf-value',
            'month' => '2026-09',
            'member' => ['id' => 12, 'alias' => 'Pessoa teste', 'username' => 'fake-user'],
            'can_manage' => true,
            'links' => [
                'uid' => 'nc-user', 'contact_id' => '35',
                'kimai_linked' => true, 'linked' => true,
            ],
            'services' => [
                'nextcloud' => [
                    'title' => 'Nextcloud', 'configured' => true, 'error' => '',
                    'fields' => [
                        ['field' => 'weight', 'label' => 'Peso', 'value' => '2',
                            'kind' => 'text', 'expected' => 'revision-placeholder'],
                        ['field' => 'dependents', 'label' => 'Dependentes', 'value' => '0',
                            'kind' => 'number', 'expected' => 'revision-placeholder'],
                        ['field' => 'tax_number', 'label' => 'CPF/CNPJ', 'value' => 'SYNTHETIC',
                            'kind' => 'text', 'expected' => 'revision-placeholder'],
                    ],
                ],
            ],
        ];
        $html = $this->twig()->render('configuracao/_member_editor.html.twig', $data);
        self::assertStringContainsString('Pessoa teste', $html);
        self::assertStringContainsString('Vínculos registrados', $html);
        self::assertStringContainsString('Vínculos entre sistemas', $html);
        self::assertSame(1, substr_count($html, 'name="field" value="tax_number"'));
        self::assertSame(1, substr_count($html, 'value="SYNTHETIC"'));
        self::assertSame(1, substr_count($html, 'name="field" value="dependents"'));
        self::assertSame(1, substr_count($html, 'name="field" value="weight"'));
        self::assertStringContainsString('Como funciona o peso de participação', $html);
        self::assertStringContainsString('name="akaunting_contact_id"', $html);
        self::assertStringContainsString('value="35"', $html);
        self::assertStringContainsString('name="_token" value="csrf-value"', $html);
        self::assertStringNotContainsString('Conferência fiscal entre as fontes', $html);
        self::assertStringNotContainsString('preferência legada', $html);
        self::assertStringNotContainsString('action="/configuracao/cooperado/12/kimai"', $html);
        self::assertStringNotContainsString('action="/configuracao/cooperado/12/akaunting"', $html);

        $readOnly = $data;
        $readOnly['can_manage'] = false;
        $readOnly['links'] = ['linked' => true, 'kimai_linked' => true];
        $reader = $this->twig()->render('configuracao/_member_editor.html.twig', $readOnly);
        self::assertStringContainsString('Peso:', $reader);
        self::assertStringContainsString('Dependentes:', $reader);
        foreach (['SYNTHETIC', 'fake-user', 'value="35"', 'ID 12',
            'name="_token"', 'name="akaunting_contact_id"',
            'name="field" value="tax_number"', 'Vínculos entre sistemas'] as $private) {
            self::assertStringNotContainsString($private, $reader);
        }

        $standalone = $this->twig()->render('configuracao.cooperado.html.twig', $data);
        self::assertStringContainsString('<html lang="pt-BR">', $standalone);
        self::assertStringContainsString('action="/configuracao/cooperado/12/nextcloud"', $standalone);
        self::assertStringContainsString('id="member-editor-dialog"', $standalone);
    }

    public function testUnlinkedMemberCanPrepareTechnicalFieldsOnlyAsAdministrator(): void
    {
        $html = $this->twig()->render('configuracao/_member_editor.html.twig', [
            'token' => 'csrf-value', 'month' => '2026-09',
            'can_manage' => true,
            'member' => ['id' => 18, 'alias' => 'Pessoa pendente'],
            'services' => ['nextcloud' => ['fields' => [], 'error' => 'Confirme vínculo']],
            'links' => ['linked' => false, 'kimai_linked' => false],
        ]);
        self::assertStringContainsString('Vínculo pendente', $html);
        self::assertStringContainsString('data-links-prepare', $html);
        self::assertStringContainsString('data-link-source="nextcloud"', $html);
        self::assertStringContainsString('data-link-source="akaunting"', $html);
    }

}
