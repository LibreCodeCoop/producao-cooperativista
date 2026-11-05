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

namespace App\Controller\Api;

use App\Service\ProducaoCooperativista;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Calculation\ApiProblemResponder;

class CapitalSocial extends AbstractController
{
    public function __construct(
        private ProducaoCooperativista $producao,
        private ApiProblemResponder $apiErrors,
    ) {
    }

    #[Route('/api/v1/capital-social', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $id = $request->query->get('contact_id');
            $until = $request->query->get('ate');
            if ($id !== null && (!is_string($id) || !ctype_digit($id) || (int) $id <= 0)) {
                throw new \InvalidArgumentException('Identificador de cooperado inválido.');
            }
            $capitalSocial = $this->producao->getCapitalSocial($id !== null ? (int) $id : null, is_string($until) ? $until : null);
        } catch (\Throwable $th) {
            return $this->apiErrors->fromThrowable($th, 'CapitalSocial');
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
