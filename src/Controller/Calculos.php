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

namespace ProducaoCooperativista\Controller;

use ProducaoCooperativista\Core\App;
use ProducaoCooperativista\Service\ProducaoCooperativista;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;

class Calculos
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private Request $request,
    ) {
    }

    public function index(): Response
    {
        $producao = App::get(ProducaoCooperativista::class);

        $inicio = \DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof \DateTime) {
            $inicio = new \DateTime();
            $inicio->modify('-2 month');
            return new RedirectResponse(
                $this->urlGenerator->generate(
                    'Calculos#index',
                    [
                        'ano-mes' => $inicio->format('Y-m')
                    ],
                    UrlGenerator::ABSOLUTE_URL
                )
            );
        }
        $producao->dates->setDiaUtilPagamento(
            (int) $this->request->get('dia-util-pagamento', getenv('DIA_UTIL_PAGAMENTO'))
        );

        $producao->dates->setInicio($inicio);

        $diasUteis = (int) $this->request->get('dias-uteis');
        $producao->dates->setDiasUteis($diasUteis);

        $producao->setPercentualMaximo(
            (int) $this->request->get('percentual-maximo', getenv('PERCENTUAL_MAXIMO'))
        );
        try {
            $data = $producao->exportData();
        } catch (\Throwable $th) {
            $erros = [$th->getMessage()];
        }

        $response = new Response(
            App::get(\Twig\Environment::class)
                ->load('calculos.index.html.twig')
                ->render([
                    'url' => $this->urlGenerator->generate(
                        'Api\Calculos#index',
                        [
                            'ano-mes' => $inicio->format('Y-m')
                        ],
                    ),
                    'data' => $data ?? [],
                    'erros' => $erros ?? [],
                ])
        );
        return $response;
    }
}
