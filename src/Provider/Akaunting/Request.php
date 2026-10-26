<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Provider\Akaunting;

use RuntimeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Request
{
    private HttpClientInterface $client;

    public function __construct(
        private LoggerInterface $logger,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    public function send(string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array
    {
        $baseUrl = rtrim(trim((string) getenv('AKAUNTING_API_BASE_URL')), '/');
        $username = trim((string) getenv('AKAUNTING_AUTH_USER'));
        $token = (string) getenv('AKAUNTING_AUTH_TOKEN');
        if ($baseUrl === '' || $username === '' || $token === '') {
            throw new RuntimeException('Configuração de acesso ao Akaunting incompleta (URL, usuário ou credencial).');
        }

        $url = str_starts_with($endpoint, 'http') ? $endpoint : $baseUrl . $endpoint;
        // Pagination URLs are server-supplied: do not forward credentials to another host.
        $host = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        if (!$host || !$baseHost
            || strcasecmp($host, $baseHost) !== 0
            || parse_url($url, PHP_URL_SCHEME) !== parse_url($baseUrl, PHP_URL_SCHEME)
            || (parse_url($url, PHP_URL_PORT) ?: null) !== (parse_url($baseUrl, PHP_URL_PORT) ?: null)) {
            throw new RuntimeException('Akaunting retornou uma URL de paginação fora do servidor configurado.');
        }

        $options = [
            'query' => $query,
            // HTTP Basic accepts the indexed [user, password] pair, not named headers.
            'auth_basic' => [$username, $token],
            'timeout' => 25,
        ];
        $companyId = (int) getenv('AKAUNTING_COMPANY_ID');
        if ($companyId > 0) {
            $options['headers'] = ['X-Company' => (string) $companyId];
        }
        if ($body !== []) {
            $options['body'] = $body;
        }

        // Never log credentials, request bodies, full URLs, or API response payloads.
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $this->logger->debug('Akaunting HTTP {method} {path}', ['method' => $method, 'path' => $path]);
        try {
            $result = $this->client->request($method, $url, $options);
            $status = $result->getStatusCode();
            $payload = $result->toArray(false);
        } catch (TransportExceptionInterface $error) {
            throw new RuntimeException(
                'Falha de conexão com a API do Akaunting. Confira DNS, rede Docker, URL e disponibilidade do serviço.',
                0,
                $error
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface $error) {
            throw new RuntimeException(
                'A API do Akaunting retornou dados que não são JSON válido. Confira a URL e o serviço.',
                0,
                $error
            );
        }

        $this->logger->debug('Akaunting HTTP {method} {path}: {status}', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
        ]);

        if (!is_array($payload)) {
            throw new RuntimeException('Resposta da API do Akaunting não contém um objeto JSON.');
        }
        if ($status >= 400) {
            // Existing document-number conflict is part of the idempotent upsert flow.
            if ($status === 422 && isset($payload['errors']['document_number'])) {
                return $payload;
            }
            throw new RuntimeException(match (true) {
                $status === 401, $status === 403 => 'Akaunting recusou a autenticação ou as permissões da API (HTTP ' . $status . ').',
                $status === 404 => 'Recurso não encontrado na API do Akaunting (HTTP 404). Confira a URL e a versão.',
                $status === 429 => 'Limite de requisições da API do Akaunting excedido (HTTP 429). Aguarde e reduza a frequência.',
                $status >= 500 => 'A API do Akaunting apresentou falha no servidor (HTTP ' . $status . '). Verifique os logs desse serviço.',
                default => 'A API do Akaunting recusou a operação (HTTP ' . $status . '). Confira validações e permissões no Akaunting.',
            });
        }

        return $payload;
    }

    public function handleError(array $response): void
    {
        if (isset($response['status_code']) && (int) $response['status_code'] >= 400) {
            throw new RuntimeException('Akaunting informou falha lógica (código ' . (int) $response['status_code'] . '). Confira as validações da operação.');
        }
        if (isset($response['errors']) && $response['errors'] !== []) {
            throw new RuntimeException('Akaunting não aceitou a operação. Confira os campos obrigatórios e a configuração dos documentos.');
        }
    }
}
