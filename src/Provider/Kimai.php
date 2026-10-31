<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Provider;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait Kimai
{
    protected function getKimaiHttpClient(): HttpClientInterface
    {
        return HttpClient::create();
    }

    public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
    {
        $base = trim((string) getenv('KIMAI_API_BASE_URL'));
        $token = trim((string) getenv('KIMAI_API_TOKEN'));
        if ($base === '' || $token === '') {
            throw new RuntimeException('Configuração Kimai incompleta: confira KIMAI_API_BASE_URL e KIMAI_API_TOKEN.');
        }
        $url = rtrim($base, '/') . $endpoint;
        $client = $this->getKimaiHttpClient();
        $isDetail = preg_match('~/[0-9]+$~D', $endpoint) === 1;
        $pageSize = 50;
        $list = [];
        $page = 1;

        do {
            if ($page > 10000) {
                throw new RuntimeException('A paginação do Kimai excedeu o limite seguro. Confira as respostas e os cabeçalhos X-Total-Pages.');
            }
            $params = $query;
            if (!$isDetail) {
                $params['page'] = $page;
                $params['size'] = $pageSize;
            }
            $result = $client->request('GET', $url, [
                'query' => $params,
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 30,
            ]);
            $status = $result->getStatusCode();
            if ($status === 404 && !$isDetail && $page > 1) {
                break;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf(
                    'Kimai recusou a consulta %s (HTTP %d). Confira endpoint, autenticação e permissões da conta de serviço.',
                    parse_url($url, PHP_URL_PATH) ?: '/api',
                    $status
                ));
            }

            $records = $result->toArray(false);
            if (!is_array($records)) {
                throw new RuntimeException('Kimai retornou uma resposta que não é uma coleção JSON válida.');
            }
            if (is_callable($parseFunction)) {
                $records = $parseFunction($records);
                if (!is_array($records)) {
                    throw new RuntimeException('O parser da resposta Kimai não retornou uma coleção.');
                }
            }
            if ($isDetail || !array_is_list($records)) {
                return $records;
            }

            $list = array_merge($list, $records);
            $headers = $result->getHeaders(false);
            $totalPages = (int) ($headers['x-total-pages'][0] ?? 0);
            $this->logger->debug('Kimai: página {page}, registros {count}, total de páginas {total}.', [
                'page' => $page,
                'count' => count($records),
                'total' => $totalPages,
            ]);
            if ($totalPages > 0) {
                if ($page >= $totalPages) {
                    break;
                }
                if ($records === []) {
                    throw new RuntimeException('Kimai retornou uma página vazia antes do término esperado da paginação.');
                }
            } elseif (count($records) < $pageSize) {
                break;
            }
            ++$page;
        } while (true);

        return $list;
    }
}
