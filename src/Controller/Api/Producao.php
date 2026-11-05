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

use App\Domain\Competencia;
use App\Service\Movimentacao;
use App\Service\Calculation\ImportedSourceReview;
use App\Service\Calculation\NetWithdrawalGoal;
use App\Service\Calculation\ProductionPublicationSelection;
use App\Service\Calculation\ProductionValueExplanations;
use App\Service\Calculation\DecimalAllocation;
use DateTime;
use App\Service\ProducaoCooperativista;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Calculation\ApiProblemResponder;

class Producao extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private ProducaoCooperativista $producao,
        private Movimentacao $movimentacao,
        private ApiProblemResponder $apiErrors,
        private ProductionPublicationSelection $selection,
        private ProductionValueExplanations $valueExplanations,
        private NetWithdrawalGoal $withdrawalGoal,
        private ImportedSourceReview $sourceReview,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/api/v1/producao', methods: ['GET'])]
    public function index(): JsonResponse
    {
        try {
            $this->producao->dates->setDiaUtilPagamento(
                $this->request->query->getInt('dia-util-pagamento', (int) getenv('DIA_UTIL_PAGAMENTO'))
            );

            $inicio = DateTime::createFromFormat('!Y-m', $this->request->query->getString('ano-mes'));
            if (!$inicio instanceof DateTime) {
                throw new \Exception('ano-mes precisa estar no formato YYYY-MM');
            }
            $this->producao->dates->setInicio($inicio);

            $diasUteis = $this->request->query->getInt('dias-uteis');
            $this->producao->dates->setDiasUteis($diasUteis);

            $this->movimentacao->setPercentualMaximo(
                $this->request->query->getInt('percentual-maximo', (int) getenv('PERCENTUAL_MAXIMO'))
            );

            $this->movimentacao->setForecastMode(true);
            $list = $this->producao->getProducaoCooperativista();
            $trabalhadoPorCliente = $this->producao->getTrabalhadoPorCliente();
            $snapshot = $this->selection->snapshot($list, $inicio->format('Y-m'));
            $output = [];
            foreach ($list as $cooperado) {
                $array = $cooperado->getProducaoCooperativista()->getValues()->toArray();
                if (empty($array['adiantamento'])) {
                    $adiantamento = '';
                } else {
                    $adiantamento = json_encode($array['adiantamento']);
                }
                $array['adiantamento'] = $adiantamento;
                $taxNumber = $cooperado->getTaxNumber();
                $array['kimai_user_id'] = $snapshot['ids'][$taxNumber];
                $array['explanations'] = $this->valueExplanations->describe(
                    $array,
                    (int) $inicio->format('Y'),
                    (int) $inicio->format('m'),
                );
                // The settlement calculation retains intermediate precision.
                // The report and payable document present values in BRL cents.
                $array['base_producao'] = DecimalAllocation::amount(
                    DecimalAllocation::cents($array['base_producao'])
                );
                $trabalhado = array_filter($trabalhadoPorCliente, fn ($c) => $c['tax_number'] === $array['tax_number']);
                $array['hours_seconds'] = array_sum(array_column($trabalhado, 'trabalhado'));
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
            try {
                $goal = $this->withdrawalGoal->evaluate($output, (int) $inicio->format('Y'), (int) $inicio->format('m'));
            } catch (\Throwable) {
                // Forecasts may be unavailable for future tax tables.
                // Never interrupt the existing settlement for a what-if goal.
                $goal = ['mode' => 'unavailable'];
            }
            $pendingInvoices = null;
            try {
                // Only the finance review is authoritative for outstanding
                // invoice statuses. A failure keeps the preview readable, but
                // never produces permission to publish.
                $review = $this->sourceReview->read(Competencia::fromString($inicio->format('Y-m')));
                $pendingInvoices = (int) $review['unconfirmed_invoices']['count'];
            } catch (\Throwable) {
                // Unknown is distinct from zero pending documents.
            }

            $unclassifiedIncome = $this->movimentacao->getUnclassifiedForecastIncome();

            $response = [
                'data' => array_values($output),
                'metadata' => [
                    'total' => count($output),
                    'revision' => $snapshot['revision'],
                    'competence' => $inicio->format('Y-m'),
                    'withdrawal_goal' => $goal,
                    'forecast_only' => true,
                    'payment_confirmed' => false,
                    'pending_invoice_count' => $pendingInvoices,
                    'unclassified_income_count' => $unclassifiedIncome['count'],
                    'unclassified_income_amount' => $unclassifiedIncome['amount'],
                    'date' => $this->producao->dates->getInicioProximoMes()->format('Y-m')
                ],
            ];
            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return $this->apiErrors->fromThrowable($e, 'Producao');
        }
    }
}
