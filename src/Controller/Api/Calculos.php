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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Calculos
{
    public function __construct(
        private Request $request,
    ) {
    }

    public function index(): Response
    {
        try {
            $producao = App::get(ProducaoCooperativista::class);

            $producao->dates->setDiaUtilPagamento(
                (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
            );

            $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
            if (!$inicio instanceof DateTime) {
                throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
            }
            $producao->dates->setInicio($inicio);

            $diasUteis = (int) $this->request->get('dias-uteis');
            $producao->dates->setDiasUteis($diasUteis);

            $producao->setPercentualMaximo(
                (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
            );

            $producao->getProducaoCooperativista();

            $response = $producao->exportData();
            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return new Response(
                $e->getMessage(),
                Response::HTTP_FORBIDDEN
            );
        }
    }
}
