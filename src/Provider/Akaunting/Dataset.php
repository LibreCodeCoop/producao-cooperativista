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

namespace App\Provider\Akaunting;

class Dataset
{
    public function __construct(
        private Request $request,
    ) {

    }
    public function list(string $endpoint, array $query = []): array
    {
        $list = [];
        while (true) {
            $response = $this->request->send(
                endpoint: $endpoint,
                query: $query,
                method: 'GET',
            );

            $list = array_merge($list, $response['data']);
            if (is_null($response['links']['next'])) {
                break;
            }
            $endpoint = $response['links']['next'];
        }
        return $list;
    }
}
