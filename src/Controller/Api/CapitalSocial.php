<?php
/**
 * @copyright Copyright (c) 2024, Vitor Mattos <vitor@php.rio>
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

namespace ProducaoCooperativista\Controller\Api;

use DateTime;
use ProducaoCooperativista\Core\App;
use ProducaoCooperativista\Service\ProducaoCooperativista;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CapitalSocial
{
    private ProducaoCooperativista $producaoCooperativista;
    public function __construct()
    {
        $this->producaoCooperativista = App::get(ProducaoCooperativista::class);
    }

    public function index(): JsonResponse
    {
        try {
            $capitalSocial = $this->producaoCooperativista->getCapitalSocial();
        } catch (\Throwable $th) {
            return new JsonResponse(
                [
                    'error' => $th->getMessage(),
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $response = [
            'data' => array_values($capitalSocial),
            'metadata' => [
                'total' => count($capitalSocial),
            ],
        ];
        return new JsonResponse($response);
    }
}
