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
    private ProducaoCooperativista $producaoCooperativista;
    public function __construct(
        private Request $request,
    ) {
        $this->producaoCooperativista = App::get(ProducaoCooperativista::class);
    }

    public function index(): JsonResponse
    {
        $this->producaoCooperativista->dates->setDiaUtilPagamento(
            (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
        );

        $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof DateTime) {
            throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
        }
        $this->producaoCooperativista->dates->setInicio($inicio);

        $diasUteis = (int) $this->request->get('dias-uteis');
        $this->producaoCooperativista->dates->setDiasUteis($diasUteis);

        $this->producaoCooperativista->setPercentualMaximo(
            (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
        );

        $type = $this->request->get('type', 'all');
        switch ($type) {
            case 'income':
                $movimentacao = $this->producaoCooperativista->getEntradas();
                break;
            case 'expense':
                $movimentacao = $this->producaoCooperativista->getSaidas();
                break;
            case 'all':
                $movimentacao = $this->producaoCooperativista->getMovimentacaoFinanceira();
                break;
        }

        $movimentacao = $this->addFlagColumn(
            $movimentacao,
            'dispendio_interno',
            'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID'
        );
        $movimentacao = $this->addFlagColumn(
            $movimentacao,
            'entrada_cliente',
            'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'
        );
        $movimentacao = $this->addFlagColumn(
            $movimentacao,
            'custos_clientes',
            'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'
        );

        $response = [
            'data' => array_values($movimentacao),
            'metadata' => [
                'total' => count($movimentacao),
                'date' => $this->producaoCooperativista->dates->getInicioProximoMes()->format('Y-m')
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
            $row[$name] = in_array($row['category_id'], $ids) ? 'sim' : '';
        });
        return $list;
    }
}
