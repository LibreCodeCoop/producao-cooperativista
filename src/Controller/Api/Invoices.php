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

namespace App\Controller\Api;

use App\Service\Akaunting\Source\Categories;
use App\Service\Movimentacao;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class Invoices extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private Categories $categories,
        private Movimentacao $movimentacao,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/api/v1/invoices', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->movimentacao->dates->setDiaUtilPagamento(
            (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
        );

        $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof DateTime) {
            throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
        }
        $this->movimentacao->dates->setInicio($inicio);

        $diasUteis = (int) $this->request->get('dias-uteis');
        $this->movimentacao->dates->setDiasUteis($diasUteis);

        $this->movimentacao->setPercentualMaximo(
            (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
        );

        $type = $this->request->get('type', 'all');
        try {
            switch ($type) {
                case 'income':
                    $movimentacao = $this->movimentacao->getEntradas();
                    break;
                case 'expense':
                    $movimentacao = $this->movimentacao->getSaidas();
                    break;
                case 'all':
                    $movimentacao = $this->movimentacao->getMovimentacaoFinanceira();
                    break;
                default:
                    throw new \Exception('Tipo de movimentação invpalido: ' . print_r($type, true));
            }
        } catch (\Throwable $th) {
            return new JsonResponse(
                [
                    'erro' => $th->getMessage()
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $movimentacao = $this->addUrlToAccount($movimentacao);
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
                'date' => $this->movimentacao->dates->getInicioProximoMes()->format('Y-m')
            ],
        ];
        return new JsonResponse($response);
    }

    private function addUrlToAccount(array $movimentacao): array
    {
        $movimentacao = array_map(function ($row) {
            if ($row['table'] === 'transaction') {
                $path = '/banking/transactions/';
            } elseif ($row['type'] === 'bill') {
                $path = '/purchases/bills/';
            } else {
                $path = '/sales/invoices/';
            }
            $row['URL'] = '<a href="' . getenv('AKAUNTING_API_BASE_URL') .
                '/' . getenv('AKAUNTING_COMPANY_ID') .
                $path . $row['id'] .
                '" target="_blank">' .
                $row['type'] . '</a>';
            return $row;
        }, $movimentacao);
        return $movimentacao;
    }

    private function addFlagColumn(array $list, string $name, string $environment): array
    {
        $ids = $this->producao->getChildrensCategories(
            (int) getenv($environment)
        );
        array_walk($list, function (&$row) use ($name, $ids) {
            $row[$name] = in_array($row['category_id'], $ids) ? 'sim' : '';
        });
        return $list;
    }
}
