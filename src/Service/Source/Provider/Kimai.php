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

trait Kimai
{
    public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
    {
        $client = HttpClient::create();
        $list = [];
        $page = 1;
        while (true) {
            $query['page'] = $page;
            $this->logger->debug('Kimai query: {query}', ['query' => $query]);
            $this->logger->debug('Kimai endpoint: {endpoint}', ['endpoint' => $endpoint]);
            $result = $client->request(
                'GET',
                rtrim($_ENV['KIMAI_API_BASE_URL'], '/') . $endpoint,
                [
                    'query' => $query,
                    'headers' => [
                        'X-AUTH-USER' => $_ENV['KIMAI_AUTH_USER'],
                        'X-AUTH-TOKEN' => $_ENV['KIMAI_AUTH_TOKEN'],
                    ],
                ]
            );
            if ($result->getStatusCode() === 404) {
                break;
            }
            $contentOfPage = $result->toArray();
            if (is_callable($parseFunction)) {
                $contentOfPage = $parseFunction($contentOfPage);
            }
            $this->logger->debug('Kimai response: {response}', ['response' => json_encode($contentOfPage)]);
            $list = array_merge($list, $contentOfPage);
            if (count($contentOfPage) < 50) {
                break;
            }
            $page++;
        }
        return $list;
    }
}