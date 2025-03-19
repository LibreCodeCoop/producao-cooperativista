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

use DateTime;
use App\Service\ProducaoCooperativista;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class Producao extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private ProducaoCooperativista $producao,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/api/v1/producao', methods: ['GET'])]
    public function index(): JsonResponse
    {
        try {
            $this->producao->dates->setDiaUtilPagamento(
                (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
            );

            $inicio = DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
            if (!$inicio instanceof DateTime) {
                throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
            }
            $this->producao->dates->setInicio($inicio);

            $diasUteis = (int) $this->request->get('dias-uteis');
            $this->producao->dates->setDiasUteis($diasUteis);

            $this->producao->setPercentualMaximo(
                (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
            );

            $list = $this->producao->getProducaoCooperativista();
            $trabalhadoPorCliente = $this->producao->getTrabalhadoPorCliente();
            $output = [];
            foreach ($list as $cooperado) {
                $array = $cooperado->getProducaoCooperativista()->getValues()->toArray();
                if (empty($array['adiantamento'])) {
                    $adiantamento = '';
                } else {
                    $adiantamento = json_encode($array['adiantamento']);
                }
                $array['adiantamento'] = $adiantamento;
                $trabalhado = array_filter($trabalhadoPorCliente, fn ($c) => $c['tax_number'] === $array['tax_number']);
                $array['trabalhado'] = json_encode(array_values(array_map(function ($row) {
                    $minutosTrabalhados = $row['trabalhado'] / 60 / 60;
                    $minutosTrabalhados = floor($minutosTrabalhados);
                    $segundosTrabalados = ($row['trabalhado'] / 60 / 60 - $minutosTrabalhados) * 100;
                    $segundosTrabalados = floor($segundosTrabalados * 60 / 100);
                    return [
                        'trabalhado' => $minutosTrabalhados . ':' . $segundosTrabalados,
                        'percentual_trabalhado' => $row['percentual_trabalhado'],
                        'total_cliente' => $row['total_cliente'] / 60 / 60,
                        'nome' => $row['name'],
                    ];
                }, $trabalhado)));
                $output[] = $array;
            }
            $response = [
                'data' => array_values($output),
                'metadata' => [
                    'total' => count($output),
                    'date' => $this->producao->dates->getInicioProximoMes()->format('Y-m')
                ],
            ];
            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return new JsonResponse(
                [
                    'erro' => $e->getMessage()
                ],
                Response::HTTP_FORBIDDEN
            );
        }
    }
}
