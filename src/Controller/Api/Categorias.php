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

namespace ProducaoCooperativista\Controller\Api;

use DateTime;
use ProducaoCooperativista\Core\App;
use ProducaoCooperativista\Service\ProducaoCooperativista;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class Categorias
{
    private ProducaoCooperativista $producaoCooperativista;
    public function __construct()
    {
        $this->producaoCooperativista = App::get(ProducaoCooperativista::class);
    }

    public function index(): JsonResponse
    {
        try {
            $categorias = $this->producaoCooperativista->getCategories();
        } catch (\Throwable $th) {
            return new JsonResponse(
                [
                    'error' => $th->getMessage(),
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $categorias = $this->addFlagColumn(
            $categorias,
            'dispendio_interno',
            'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID'
        );
        $categorias = $this->addFlagColumn(
            $categorias,
            'entrada_cliente',
            'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'
        );
        $categorias = $this->addFlagColumn(
            $categorias,
            'custos_clientes',
            'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'
        );

        $response = [
            'data' => array_values($categorias),
            'metadata' => [
                'total' => count($categorias),
            ],
        ];
        return new JsonResponse($response);
    }

    private function addFlagColumn(array $list, string $name, string $environment): array
    {
        $ids = $this->producaoCooperativista->getChildrensCategories(
            (int) getenv($environment)
        );
        array_walk($list, function (&$row) use ($name, $ids) {
            $row[$name] = in_array($row['id'], $ids) ? 'sim' : '';
        });
        return $list;
    }
}
