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

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Request {
    protected HttpClientInterface $client;

    public function __construct() {
        $this->client = HttpClient::create();
    }

    public function send(string $endpoint, array $body = [], array $query = [], string $method = 'POST'): array
    {
        $options = [
            'query' => $query,
            'auth_basic' => [
                'X-AUTH-USER' => $_ENV['AKAUNTING_AUTH_USER'],
                'X-AUTH-TOKEN' => $_ENV['AKAUNTING_AUTH_TOKEN'],
            ]
        ];
        if (!empty($body)) {
            $options['body'] = $body;
        }
        $result = $this->client->request(
            $method,
            rtrim($_ENV['AKAUNTING_API_BASE_URL'], '/') . $endpoint,
            $options,
        );
        $response = $result->toArray(false);

        return $response;
    }
}
