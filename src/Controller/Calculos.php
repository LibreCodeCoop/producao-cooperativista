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

namespace App\Controller;

use App\Domain\Competencia;
use App\Service\Movimentacao;
use App\Service\Calculation\ImportedSourceReview;
use App\Service\Calculation\FailureGuide;
use App\Service\ProducaoCooperativista;
use App\Service\Config\CompetenceDefaults;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Calculos extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private CompetenceDefaults $competences,
        private ProducaoCooperativista $producao,
        private Movimentacao $movimentacao,
        private ImportedSourceReview $sourceReview,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/relatorios/calculos', methods: ['GET'])]
    public function index(): Response
    {
        $requested = $this->request->query->getString('ano-mes');
        if ($requested === '') {
            $inicio = \DateTime::createFromFormat('!Y-m', $this->competences->reportMonth());
            return new RedirectResponse($this->urlGenerator->generate('app_calculos_index', [
                'ano-mes' => $inicio->format('Y-m'),
            ]));
        }
        try {
            $inicio = Competencia::fromString($requested)->start();
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $simulation = array_any(
            ['percentual-maximo', 'dia-util-pagamento', 'dias-uteis'],
            fn (string $key): bool => $this->request->query->has($key),
        );

        try {
            $this->producao->dates->setDiaUtilPagamento(
                $this->request->query->getInt('dia-util-pagamento', (int) getenv('DIA_UTIL_PAGAMENTO'))
            );

            $this->producao->dates->setInicio($inicio);

            $diasUteis = $this->request->query->getInt('dias-uteis');
            $this->producao->dates->setDiasUteis($diasUteis);

            $this->movimentacao->setPercentualMaximo(
                $this->request->query->getInt('percentual-maximo', (int) getenv('PERCENTUAL_MAXIMO'))
            );
            // Financial memory is a forecast; the publishing command keeps
            // its paid-only selection and separate financial review.
            $this->movimentacao->setForecastMode(true);
            $data = $this->producao->exportData();
        } catch (\Throwable $th) {
            $guide = FailureGuide::diagnose($th->getMessage());
            $erros = [$guide->summary . ' ' . $guide->instructions];
        }
        try {
            // On success: expose invoice statuses beside the full forecast.
            // On failure: retain an auditable summary of the imported sources.
            $sourceReview = $this->sourceReview->read(Competencia::fromString($requested));
        } catch (\Throwable) {
            // Never hide a forecast or its original diagnostic when status
            // reconciliation is temporarily unavailable.
        }

        return $this->render('calculos.index.html.twig', [
            'url' => $this->urlGenerator->generate(
                'app_api_calculos_index',
                [
                    'ano-mes' => $inicio->format('Y-m')
                ],
            ),
            'data' => $data ?? [],
            'competencia' => $inicio->format('Y-m'),
            'financial_month' => Competencia::fromString($requested)->financialMonth(),
            'source_review' => $sourceReview ?? null,
            'unclassified_income' => isset($data)
                ? $this->movimentacao->getUnclassifiedForecastIncome()
                : null,
            'simulation' => $simulation,
            'erros' => $erros ?? [],
        ]);
    }
    #[Route('/relatorios/calculos/ajuda', methods: ['GET'])]
    public function ajuda(): Response
    {
        return $this->render('calculos.ajuda.html.twig');
    }

}
