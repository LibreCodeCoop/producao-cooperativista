<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Competencia;
use App\Service\Calculation\ApiProblemResponder;
use App\Service\Import\ImportedDataAvailability;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only availability check. Never imports, deletes or publishes data.
 * @psalm-suppress UnusedClass Registered by Symfony routing attributes.
 */
final readonly class ImportedDataAvailabilityController
{
    public function __construct(
        private ImportedDataAvailability $availability,
        private ApiProblemResponder $errors,
    ) {
    }

    #[Route('/api/v1/producao/disponibilidade', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $competence = Competencia::fromString($request->query->getString('ano-mes'));

            return new JsonResponse($this->availability->forCompetence($competence));
        } catch (\Throwable $error) {
            return $this->errors->fromThrowable($error, 'ImportedDataAvailability');
        }
    }
}
