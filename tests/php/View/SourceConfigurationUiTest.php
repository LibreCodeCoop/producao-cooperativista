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

    public function testLockedPortalDoesNotExposePersonalProfilesOrEditForms(): void
    {
        $html = $this->twig()->render('configuracao.index.html.twig', [
            'unlocked' => false,
            'token' => 'csrf-value',
            'month' => '2026-08',
            'users' => [],
            'member' => null,
            'services' => [],
            'error' => '',
            'notifications' => [],
        ]);
        self::assertStringContainsString('Desbloquear administração', $html);
        self::assertStringContainsString('action="/configuracao/desbloquear"', $html);
        self::assertStringContainsString('name="action_token"', $html);
        self::assertStringNotContainsString('cooperado/12', $html);
        self::assertStringNotContainsString('Kimai · Preferências', $html);
    }

    public function testUnlockedPortalHasRealSourceEditActionsAndReadOnlySnapshotNotice(): void
    {
        $html = $this->twig()->render('configuracao.index.html.twig', [
            'unlocked' => true,
            'token' => 'csrf-value',
            'month' => '2026-08',
            'users' => [[
                'id' => 12, 'username' => 'fake-user', 'alias' => 'Exemplo',
                'hours' => 10.5, 'participates' => true, 'system' => false, 'enabled' => true,
            ]],
            'member' => ['id' => 12, 'alias' => 'Exemplo', 'username' => 'fake-user'],
            'services' => [
                'nextcloud' => [
                    'title' => 'Nextcloud · Profile Fields', 'configured' => true,
                    'error' => '', 'description' => 'Fonte Nextcloud',
                    'fields' => [['field' => 'weight', 'label' => 'Peso', 'value' => '2',
                        'kind' => 'text', 'expected' => 'opaque-hmac']],
                ],
                'kimai' => [
                    'title' => 'Kimai · Preferências', 'configured' => true,
                    'error' => '', 'description' => 'Fonte Kimai',
                    'fields' => [['field' => 'dependents', 'label' => 'Dependentes', 'value' => '0',
                        'kind' => 'number', 'expected' => 'opaque-hmac']],
                ],
                'akaunting' => [
                    'title' => 'Akaunting · Contato', 'configured' => false, 'description' => '',
                    'error' => 'API de contatos: HTTP 403', 'fields' => [],
                ],
            ],
            'error' => '',
            'notifications' => [],
        ]);
        self::assertStringContainsString('10,50 h', $html);
        self::assertStringContainsString('name="expected" value="opaque-hmac"', $html);
        self::assertStringContainsString('action="/configuracao/cooperado/12/nextcloud"', $html);
        self::assertStringContainsString('action="/configuracao/cooperado/12/kimai"', $html);
        self::assertStringContainsString('HTTP 403', $html);
        self::assertStringContainsString('global', $html);
        self::assertStringNotContainsString('action="/configuracao/cooperado/12/akaunting"', $html);
        self::assertStringNotContainsString('name="action_token"', $html);
    }
}
