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

use App\Service\ProducaoCooperativista;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Calculos extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private ProducaoCooperativista $producao,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/calculos', methods: ['GET'])]
    public function index(): Response
    {
        $inicio = \DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof \DateTime) {
            $inicio = new \DateTime();
            $inicio->modify('-2 month');
            return new RedirectResponse(
                $this->urlGenerator->generate(
                    'app_calculos_index',
                    [
                        'ano-mes' => $inicio->format('Y-m')
                    ],
                    $this->urlGenerator::ABSOLUTE_URL
                )
            );
        }
        $this->producao->dates->setDiaUtilPagamento(
            (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
        );

        $this->producao->dates->setInicio($inicio);

        $diasUteis = (int) $this->request->get('dias-uteis');
        $this->producao->dates->setDiasUteis($diasUteis);

        $this->producao->setPercentualMaximo(
            (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
        );
        try {
            $data = $this->producao->exportData();
        } catch (\Throwable $th) {
            $erros = [$th->getMessage()];
        }

        return $this->render('calculos.index.html.twig', [
            'url' => $this->urlGenerator->generate(
                'app_api_calculos_index',
                [
                    'ano-mes' => $inicio->format('Y-m')
                ],
            ),
            'data' => $data ?? [],
            'erros' => $erros ?? [],
        ]);
    }
}
