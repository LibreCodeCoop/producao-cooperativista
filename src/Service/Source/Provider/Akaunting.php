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

namespace KimaiClient\Service\Source\Provider;

use Symfony\Component\HttpClient\HttpClient;

trait Akaunting
{
    public function doRequestAkaunting(string $endpoint, array $query = []): array
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
                ]
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
}
