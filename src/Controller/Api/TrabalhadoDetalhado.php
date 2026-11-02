<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Competencia;
use App\Service\Calculation\ApiProblemResponder;
use App\Service\Reporting\ImportedTimesheetReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class TrabalhadoDetalhado extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony DI. */
    public function __construct(
        private ImportedTimesheetReport $report,
        private ApiProblemResponder $apiErrors,
    ) {
    }

    #[Route('/api/v1/apontamentos', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $competence = Competencia::fromString($request->query->getString('ano-mes'));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $rows = $this->report->rows($competence);
        } catch (\Throwable $error) {
            return $this->apiErrors->fromThrowable($error, 'TrabalhadoDetalhado');
        }
        $response = new JsonResponse([
            'competence' => (string) $competence,
            'data' => $rows,
            'metadata' => ['total' => count($rows), 'source' => 'kimai_imported_snapshot'],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
