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

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Index extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private KernelInterface $kernel
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        $application = new Application($this->kernel);

        return $this->render('index.index.html.twig', [
            'relatorios' => [
                'calculos' => [
                    'url' => $this->urlGenerator->generate('app_calculos_index'),
                    'label' => 'Cálculos',
                ],
                'categorias' => [
                    'url' => $this->urlGenerator->generate('app_categorias_index'),
                    'label' => 'Categorias',
                ],
                'invoices' => [
                    'url' => $this->urlGenerator->generate('app_invoices_index'),
                    'label' => 'Entradas e saídas',
                ],
                'capital-social-summarized' => [
                    'url' => $this->urlGenerator->generate('app_capitalsocialsummarized_index'),
                    'label' => 'Capital social',
                ],
                'producao' => [
                    'url' => $this->urlGenerator->generate('app_producao_index'),
                    'label' => 'Produção',
                ],
            ],
            'acoes' => [
                'zerar_banco_local' => [
                    'url' => $this->urlGenerator->generate('app_acoes_zerarbancolocal'),
                    'label' => 'Zerar banco local',
                ],
                'executa_producao' => [
                    'url' => $this->urlGenerator->generate('app_acoes_makeproducao'),
                    'label' => $application->find('make:producao')->getName(),
                ],
            ],
        ]);
    }
}
