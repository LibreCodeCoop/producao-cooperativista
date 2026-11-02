<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Competencia;
use App\Service\Calculation\ApiProblemResponder;
use App\Service\Calculation\ImportedSourceReview;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only inspection of staged inputs when the payable preview is blocked.
 * @psalm-suppress UnusedClass Registered by Symfony routing attributes.
 */
final readonly class ImportedSourceReviewController
{
    public function __construct(
        private ImportedSourceReview $review,
        private ApiProblemResponder $errors,
    ) {
    }

    #[Route('/api/v1/producao/fontes', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $competence = Competencia::fromString($request->query->getString('ano-mes'));
            return new JsonResponse($this->review->read($competence));
        } catch (\Throwable $error) {
            return $this->errors->fromThrowable($error, 'ImportedSourceReview');
        }
    }
}
