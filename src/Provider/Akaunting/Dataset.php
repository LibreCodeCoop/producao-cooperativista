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
        // Akaunting may return links.next containing only ?page=N, dropping
        // the company scope and date/type filters. Keep these fixed across
        // the complete read without overriding the API's pagination cursor.
        $scope = array_diff_key($query, ['page' => true]);
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
            $nextParams = [];
            $nextQuery = parse_url($next, PHP_URL_QUERY);
            if (is_string($nextQuery)) {
                parse_str($nextQuery, $nextParams);
            }
            $query = [];
            foreach ($scope as $key => $value) {
                if (array_key_exists($key, $nextParams)) {
                    if ($nextParams[$key] != $value) {
                        throw new RuntimeException('A paginação do Akaunting alterou os filtros ou a empresa da consulta.');
                    }
                    continue;
                }
                $query[$key] = $value;
            }
        }
        return $all;
    }
}
