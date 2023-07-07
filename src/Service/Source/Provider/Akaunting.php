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

namespace ProducaoCooperativista\Service\Source\Provider;

use Symfony\Component\HttpClient\HttpClient;

trait Akaunting
{
    private array $dictionaryTextParams = [
        'NFSe' => 'nfse',
        'Transação do mês' => 'transaction_of_month',
        'CNPJ cliente' => 'customer',
        'CNPJ' => 'customer',
        'Setor' => 'sector',
        'setor' => 'sector',
        'Arquivar' => 'archive',
    ];

    public function getDataList(string $endpoint, array $query = []): array
    {
        $client = HttpClient::create();
        $list = [];
        while (true) {
            $this->logger->debug('Akaunting query: {query}', ['query' => $query]);
            $this->logger->debug('Akaunting endpoint: {endpoint}', ['endpoint' => $endpoint]);
            $result = $client->request(
                'GET',
                rtrim($_ENV['AKAUNTING_API_BASE_URL'], '/') . $endpoint,
                [
                    'query' => $query,
                    'auth_basic' => [
                        'X-AUTH-USER' => $_ENV['AKAUNTING_AUTH_USER'],
                        'X-AUTH-TOKEN' => $_ENV['AKAUNTING_AUTH_TOKEN'],
                    ],
                ],
            );
            $response = $result->toArray();
            $this->logger->debug('Akaunting response: {response}', ['response' => json_encode($response['data'])]);
            $list = array_merge($list, $response['data']);
            if (is_null($response['links']['next'])) {
                break;
            }
            $endpoint = $response['links']['next'];
        }
        return $list;
    }

    public function sendData(string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array
    {
        $client = HttpClient::create();
        $result = $client->request(
            $method,
            rtrim($_ENV['AKAUNTING_API_BASE_URL'], '/') . $endpoint,
            [
                'query' => $query,
                'body' => $body,
                'auth_basic' => [
                    'X-AUTH-USER' => $_ENV['AKAUNTING_AUTH_USER'],
                    'X-AUTH-TOKEN' => $_ENV['AKAUNTING_AUTH_TOKEN'],
                ]
            ],
        );
        $response = $result->toArray(false);
        return $response;
    }

    public function parseText(string $text): array
    {
        $return = [];
        if (empty($text)) {
            return $return;
        }
        $explodedText = explode("\n", $text);
        $pattern = '/^(?<paramName>' . implode('|', array_keys($this->dictionaryTextParams)) . '): (?<paramValue>.*)$/i';
        foreach ($explodedText as $row) {
            if (!preg_match($pattern, $row, $matches)) {
                continue;
            }
            $return[$this->dictionaryTextParams[$matches['paramName']]] = strtolower(trim($matches['paramValue']));
        }
        return $return;
    }
}
