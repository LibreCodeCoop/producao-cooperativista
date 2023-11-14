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

class Invoices
{
    public function __construct(
        private Request $request,
    ) {
    }

    public function index(): JsonResponse
    {
        $producaoCooperativista = App::get(ProducaoCooperativista::class);

        $producaoCooperativista->dates->setDiaUtilPagamento(
            (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
        );

        $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof DateTime) {
            throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
        }
        $producaoCooperativista->dates->setInicio($inicio);

        $diasUteis = (int) $this->request->get('dias-uteis');
        $producaoCooperativista->dates->setDiasUteis($diasUteis);

        $percentualMaximo = (int) $this->request->get('percentual-maximo');
        $producaoCooperativista->setPercentualMaximo($percentualMaximo);

        $type = $this->request->get('type', 'all');
        switch ($type) {
            case 'income':
                $movimentacao = $producaoCooperativista->getEntradas();
                break;
            case 'expense':
                $movimentacao = $producaoCooperativista->getSaidas();
                break;
            case 'all':
                $movimentacao = $producaoCooperativista->getMovimentacaoFinanceira();
                break;
        }
        $response = [
            'data' => $movimentacao,
            'metadata' => [
                'total' => count($movimentacao),
                'date' => $producaoCooperativista->dates->getInicioProximoMes()->format('Y-m')
            ],
        ];
        return new JsonResponse($response);
    }
}
