<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AcoesHttpTest extends WebTestCase
{
    public function testDestructiveExecutionRejectsGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/acoes/do-make-producao');

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
    }

    public function testIndexPreservesTheManualEtlRebuildAction(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Limpar dados importados (ETL)', $client->getResponse()->getContent());
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
        self::assertStringContainsString('href="/calculos?ano-mes=2026-08"', $client->getResponse()->getContent());
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
