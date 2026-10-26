<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ApiProblemResponder;
use DomainException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ApiProblemResponderTest extends TestCase
{
    public function testClientGetsGuidanceButNotTaxNumbersOrTokens(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $response = (new ApiProblemResponder($logger))->fromThrowable(
            new DomainException('Código de cliente inválido, CPF 11122233344, senha secreto-123'),
            'producao'
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CLIENTE_SEM_CODIGO', $data['code']);
        self::assertNotEmpty($data['resolution']);
        self::assertNotEmpty($data['request_id']);
        self::assertStringNotContainsString('11122233344', $response->getContent());
        self::assertStringNotContainsString('secreto-123', $response->getContent());
    }

    public function testExternalFailureIsNotIncorrectlyReportedAsForbidden(): void
    {
        $response = (new ApiProblemResponder($this->createMock(LoggerInterface::class)))
            ->fromThrowable(new RuntimeException('Could not resolve host: akaunting.nginx'), 'calculos');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }
}
