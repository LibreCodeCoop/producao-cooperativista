<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiProblemResponder
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function fromThrowable(Throwable $error, string $operation): JsonResponse
    {
        $id = bin2hex(random_bytes(8));
        $guide = FailureGuide::diagnose($error->getMessage());

        $this->logger->error('Falha de API {operation} identificada como {id}', [
            'operation' => $operation,
            'id' => $id,
            'exception' => $error,
        ]);

        $status = match (true) {
            $error instanceof InvalidArgumentException => Response::HTTP_BAD_REQUEST,
            $error instanceof DomainException => Response::HTTP_UNPROCESSABLE_ENTITY,
            in_array($guide->code, ['SERVICO_INACESSIVEL', 'API_LIMITADA', 'API_ACESSO', 'API_RESPOSTA'], true)
                => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        return new JsonResponse([
            'error' => $guide->summary,
            'erro' => $guide->summary,
            'code' => $guide->code,
            'resolution' => $guide->instructions,
            'request_id' => $id,
        ], $status);
    }
}
