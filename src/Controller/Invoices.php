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
use App\Service\Config\CompetenceDefaults;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Invoices extends AbstractController
{
    private Request $request;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private CompetenceDefaults $competences,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/relatorios/invoices', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->request->query->getString('pendentes') === '1') {
            // Compatibility for links created before invoice reconciliation
            // was separated into its own report. It is not a table filter.
            $requested = $this->request->query->getString('ano-mes');
            try {
                $period = Competencia::fromString($requested ?: $this->competences->reportMonth());
            } catch (\InvalidArgumentException $error) {
                return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
            }
            return new RedirectResponse($this->urlGenerator->generate('app_invoices_pending', [
                'ano-mes' => (string) $period,
            ]), Response::HTTP_FOUND);
        }

        return $this->renderReport(false);
    }

    #[Route('/relatorios/faturas-conferencia', name: 'app_invoices_pending', methods: ['GET'])]
    public function pending(): Response
    {
        return $this->renderReport(true);
    }

    private function renderReport(bool $pendingOnly): Response
    {
        $requested = $this->request->query->getString('ano-mes');
        if ($requested === '') {
            // The reports catalog links here without a month. Render the
            // latest imported period directly instead of depending on redirect.
            $requested = $this->competences->reportMonth();
        }

        try {
            $inicio = Competencia::fromString($requested)->start();
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $params = ['ano-mes' => $inicio->format('Y-m')];
        $apiRoute = $pendingOnly ? 'app_api_invoices_pending' : 'app_api_invoices_index';

        return $this->render('invoices.index.html.twig', [
            'url' => $this->urlGenerator->generate($apiRoute, $params),
            'competencia' => $inicio->format('Y-m'),
            'financial_month' => Competencia::fromString($requested)->financialMonth(),
            'pendentes' => $pendingOnly,
            'workflow_asset_version' => substr(
                (string) hash_file('sha256', $this->getParameter('kernel.project_dir')
                    . '/public/js/report-table.js'),
                0,
                12,
            ),
        ]);
    }
}
