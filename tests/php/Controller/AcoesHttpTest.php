<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AcoesHttpTest extends WebTestCase
{
    public function testImportAvailabilityIsReadOnlyAndRejectsInvalidCompetence(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/producao/disponibilidade?ano-mes=not-a-month');
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/producao/disponibilidade', ['ano-mes' => '2026-09']);
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
    }

    public function testDestructiveExecutionRejectsGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/do-make-producao');

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
    }

    public function testIndexShowsOneClosingActionAndResetRemainsInImportStep(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('/acoes/make-producao', $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/acoes/make-producao?ano-mes=2026-08');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Limpar base importada', $client->getResponse()->getContent());
        self::assertStringContainsString(
            'href="/acoes/zerar-banco-local?retorno=2026-08"',
            $client->getResponse()->getContent(),
        );
    }

    public function testProductionShowsOnlyItsOwnSourcesAndRejectsSwitch(): void
    {
        $previousStage = getenv('APP_DEPLOYMENT_STAGE');
        $previousAdmins = getenv('CONFIG_ADMIN_USERS');
        try {
            putenv('APP_DEPLOYMENT_STAGE=production');
            putenv('CONFIG_ADMIN_USERS=librecode');
            $client = static::createClient();
            $crawler = $client->request('GET', '/acoes/zerar-banco-local', server: [
                'REMOTE_USER' => 'librecode',
            ]);
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Ambiente de execução:', $html);
            self::assertStringContainsString('Este ambiente de produção não permite trocar suas fontes', $html);
            self::assertStringNotContainsString('action="/acoes/origem-dados"', $html);

            $client->request('POST', '/acoes/origem-dados', [
                '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
                'profile' => 'staging', 'version' => '0',
            ], server: ['REMOTE_USER' => 'librecode']);
            self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        } finally {
            putenv($previousStage === false ? 'APP_DEPLOYMENT_STAGE' : 'APP_DEPLOYMENT_STAGE=' . $previousStage);
            putenv($previousAdmins === false ? 'CONFIG_ADMIN_USERS' : 'CONFIG_ADMIN_USERS=' . $previousAdmins);
        }
    }

    public function testSourceSwitchRequiresCsrfAndNeverAcceptsGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/origem-dados');
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
        $client->request('POST', '/acoes/origem-dados', ['profile' => 'production', 'version' => '0']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testEtlResetPostWithoutCsrfIsForbidden(): void
    {
        $client = static::createClient();
        $client->request('POST', '/acoes/zerar-banco-local', ['confirmacao' => 'ZERAR']);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testResetFormGetCannotDropAnyTable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/zerar-banco-local');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Digite ZERAR', $client->getResponse()->getContent());
    }

    public function testRequestWithoutCsrfCannotRunCalculation(): void
    {
        $client = static::createClient();
        $client->request('POST', '/acoes/do-make-producao', [
            'year' => '2026',
            'month' => '08',
            'baixar_dados' => '0',
            'atualiza_producao' => '0',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testImportAndFinancialPublicationCannotOccurInSameRequest(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/acoes/make-producao?ano-mes=2026-08');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/acoes/do-make-producao', [
            '_token' => $csrf, 'year' => '2026', 'month' => '08',
            'baixar_dados' => '1', 'atualiza_producao' => '1',
            'operation' => 'publish',
            'expected_revision' => str_repeat('a', 64),
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('não pode reimportar', $client->getResponse()->getContent());
    }

    public function testPublicationWithoutReviewedSnapshotIsRejectedBeforeExecution(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/acoes/make-producao?ano-mes=2026-08');
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/acoes/do-make-producao', [
            '_token' => $csrf,
            'year' => '2026', 'month' => '08',
            'baixar_dados' => '0',
            'atualiza_producao' => '1',
            'operation' => 'publish',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Revisão ausente', $client->getResponse()->getContent());
    }

    public function testProcessPageRestoresPeriodFromAuditLinks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/make-producao?ano-mes=2026-08');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('href="/relatorios/calculos?ano-mes=2026-08"', $client->getResponse()->getContent());
    }

    public function testInvalidMonthQueryIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/make-producao?ano-mes=2026-13');
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testMalformedPeriodReturnsErrorBeforeAnyExecution(): void
    {
        $client = static::createClient();
        $client->request('POST', '/acoes/do-make-producao', ['year' => '2026', 'month' => '13']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }
}
