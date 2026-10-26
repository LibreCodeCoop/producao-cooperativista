<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Provider\Akaunting;

use RuntimeException;

class Dataset
{
    public function __construct(private Request $request)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $endpoint, array $query = []): array
    {
        $all = [];
        $visited = [];
        $page = 0;
        while ($endpoint !== '') {
            if (++$page > 1000 || isset($visited[$endpoint])) {
                throw new RuntimeException('Paginação circular ou excessiva na API do Akaunting. Verifique links.next.');
            }
            $visited[$endpoint] = true;
            $response = $this->request->send(endpoint: $endpoint, query: $query, method: 'GET');
            $this->request->handleError($response);

            if (!isset($response['data']) || !is_array($response['data']) || !array_is_list($response['data'])) {
                throw new RuntimeException(sprintf(
                    'Resposta inesperada da API do Akaunting na página %d: campo data ausente ou inválido. Verifique autenticação, URL e versão da API.',
                    $page
                ));
            }
            $all = array_merge($all, $response['data']);
            $next = $response['links']['next'] ?? null;
            if ($next === null || $next === '') {
                break;
            }
            if (!is_string($next)) {
                throw new RuntimeException('Resposta da API do Akaunting possui link de próxima página inválido.');
            }
            $endpoint = $next;
            // Follow pagination URLs as supplied by the API without accumulating
            // the first page's search filters a second time.
            $query = [];
        }
        return $all;
    }
}
