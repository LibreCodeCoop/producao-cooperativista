<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Uses the list endpoint's fields whenever available. Missing details are
 * fetched concurrently in bounded groups to avoid serial N+1 HTTP latency.
 */
final class KimaiDetailsLoader
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $requiredFields
     * @return list<array<string, mixed>>
     */
    public function enrich(array $rows, string $resource, array $requiredFields): array
    {
        $baseUrl = rtrim(trim((string) getenv('KIMAI_API_BASE_URL')), '/');
        $token = trim((string) getenv('KIMAI_API_TOKEN'));
        if ($rows === []) {
            return [];
        }
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Credenciais da API do Kimai incompletas: confira KIMAI_API_BASE_URL e KIMAI_API_TOKEN.');
        }

        $pending = [];
        foreach ($rows as $index => $row) {
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $row)) {
                    $pending[$index] = $row;
                    break;
                }
            }
        }

        foreach (array_chunk($pending, 12, true) as $batch) {
            $responses = [];
            // Send every request first; HttpClient multiplexes responses.
            foreach ($batch as $index => $row) {
                $id = $row['id'] ?? null;
                if (!is_scalar($id) || !preg_match('/^[0-9]+$/D', (string) $id)) {
                    throw new RuntimeException('Registro do Kimai sem ID numérico válido.');
                }
                $responses[$index] = $this->httpClient->request(
                    'GET',
                    $baseUrl . '/' . $resource . '/' . $id,
                    ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 25]
                );
            }
            foreach ($responses as $index => $response) {
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new RuntimeException(sprintf(
                        'API do Kimai respondeu HTTP %d ao consultar detalhes de %s. Confira credenciais, IDs e disponibilidade.',
                        $status,
                        $resource
                    ));
                }
                $details = $response->toArray(false);
                if (!is_array($details)) {
                    throw new RuntimeException('API do Kimai retornou detalhes em formato inválido.');
                }
                foreach ($requiredFields as $field) {
                    if (!array_key_exists($field, $details)) {
                        throw new RuntimeException(sprintf(
                            'Resposta de %s no Kimai não contém o campo obrigatório %s.',
                            $resource,
                            $field
                        ));
                    }
                    $rows[$index][$field] = $details[$field];
                }
            }
        }

        return $rows;
    }
}
