<?php

/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace ProducaoCooperativista\Provider\Akaunting;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Request
{
    protected HttpClientInterface $client;

    public function __construct(
        private LoggerInterface $logger,
    ) {
        $this->client = HttpClient::create();
    }

    public function send(string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array
    {
        if (!str_starts_with($endpoint, 'http')) {
            $endpoint = rtrim(getenv('AKAUNTING_API_BASE_URL'), '/') . $endpoint;
        }
        $options = [
            'query' => $query,
            'auth_basic' => [
                'X-AUTH-USER' => getenv('AKAUNTING_AUTH_USER'),
                'X-AUTH-TOKEN' => getenv('AKAUNTING_AUTH_TOKEN'),
            ]
        ];
        if (!empty($body)) {
            $options['body'] = $body;
        }
        $this->logger->debug(sprintf(
            "Requisição para a API do Akaunting:\n%s",
            json_encode([
                'method' => $method,
                'endpoint' => $endpoint,
                'options' => $options,
            ])
        ));
        $result = $this->client->request(
            $method,
            $endpoint,
            $options,
        );
        $response = $result->toArray(false);
        $this->logger->debug(sprintf(
            "Resposta da API do Akaunting:\n%s",
            json_encode($response)
        ));

        return $response;
    }

    public function handleError($response): void
    {
        if (!isset($response['status_code'])) {
            return;
        }
        if ($response['status_code'] === 429) {
            if (isset($response['message']) && $response['message'] === 'Too Many Attempts.') {
                throw new Exception('Excesso de requisições para a API do Akaunting.');
            }
            throw new Exception($response['message']);
        } elseif ($response['status_code'] === 500) {
            if (str_contains($response['message'], 'No query results for model')) {
                throw new Exception(sprintf(
                    "Informação não encontrada no Akaunting.\n" .
                    "%s",
                    $response['message']
                ));
            }
        }
        throw new Exception($response['message']);
    }
}
