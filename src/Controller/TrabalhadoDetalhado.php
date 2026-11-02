<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Competencia;
use App\Service\Config\CompetenceDefaults;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class TrabalhadoDetalhado extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony DI. */
    public function __construct(private CompetenceDefaults $competences)
    {
    }

    #[Route('/relatorios/apontamentos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $month = (string) Competencia::fromString(
                $request->query->getString('ano-mes') ?: $this->competences->reportMonth(),
            );
        } catch (\InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $kimaiApi = rtrim((string) getenv('KIMAI_API_BASE_URL'), '/');
        $kimaiBase = preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?/api$~iD', $kimaiApi)
            ? substr($kimaiApi, 0, -4) . '/en/timesheet/' : null;

        $response = $this->render('trabalhadoDetalhado.index.html.twig', [
            'competencia' => $month,
            'url' => $this->generateUrl('app_api_trabalhadodetalhado_index', ['ano-mes' => $month]),
            'kimai_timesheet_url' => $kimaiBase,
            'workflow_asset_version' => substr((string) hash_file(
                'sha256',
                (string) $this->getParameter('kernel.project_dir') . '/public/js/report-table.js',
            ), 0, 12),
            'timesheet_asset_version' => substr((string) hash_file(
                'sha256',
                (string) $this->getParameter('kernel.project_dir') . '/public/js/timesheet-report.js',
            ), 0, 12),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
