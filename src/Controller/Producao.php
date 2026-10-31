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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Producao extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/producao', methods: ['GET'])]
    public function index(): Response
    {
        $requested = $this->request->query->getString('ano-mes');
        if ($requested === '') {
            $inicio = new \DateTime('first day of -2 months');
            return new RedirectResponse(
                $this->urlGenerator->generate(
                    'app_producao_index',
                    [
                        'ano-mes' => $inicio->format('Y-m')
                    ],
                    $this->urlGenerator::ABSOLUTE_URL
                )
            );
        }

        try {
            $inicio = Competencia::fromString($requested)->start();
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->render('producao.index.html.twig', [
            'url' => $this->urlGenerator->generate(
                'app_api_producao_index',
                [
                    'ano-mes' => $inicio->format('Y-m')
                ],
            ),
            'competencia' => $inicio->format('Y-m'),
        ]);
    }
}
