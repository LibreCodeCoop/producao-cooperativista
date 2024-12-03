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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TrabalhadoSummarized
{
    private ProducaoCooperativista $producaoCooperativista;
    public function __construct(
        private Request $request,
    ) {
        $this->producaoCooperativista = App::get(ProducaoCooperativista::class);
    }

    public function index(): JsonResponse
    {
        try {

            $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
            if (!$inicio instanceof DateTime) {
                throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
            }
            $this->producaoCooperativista->dates->setInicio($inicio);
            $trabalhadoSummarized = $this->producaoCooperativista->getTrabalhadoSummarized();
        } catch (\Throwable $th) {
            return new JsonResponse(
                [
                    'error' => $th->getMessage(),
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $response = [
            'data' => array_values($trabalhadoSummarized),
            'metadata' => [
                'total' => count($trabalhadoSummarized),
            ],
        ];
        return new JsonResponse($response);
    }
}
