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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Invoices extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/invoices', methods: ['GET'])]
    public function index(): Response
    {
        $inicio = \DateTime::createFromFormat('Y-m', $this->request->get('ano-mes', ''));
        if (!$inicio instanceof \DateTime) {
            $inicio = new \DateTime();
            $inicio->modify('-2 month');
            return new RedirectResponse(
                $this->urlGenerator->generate(
                    'app_invoices_index',
                    [
                        'ano-mes' => $inicio->format('Y-m')
                    ],
                    $this->urlGenerator::ABSOLUTE_URL
                )
            );
        }

        return $this->render('invoices.index.html.twig', [
            'url' => $this->urlGenerator->generate(
                'app_api_invoices_index',
                [
                    'ano-mes' => $inicio->format('Y-m')
                ],
            ),
        ]);
        return $response;
    }
}
