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

    public function testMalformedPeriodReturnsErrorBeforeAnyExecution(): void
    {
        $client = static::createClient();
        $client->request('POST', '/acoes/do-make-producao', ['year' => '2026', 'month' => '13']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }
}
