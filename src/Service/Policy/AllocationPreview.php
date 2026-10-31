<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use App\Domain\Competencia;
use App\Service\Cooperative\ProfileRegistry;
use App\Service\Kimai\Source\WorkMetricsReader;
use LogicException;

/** Pure business workflow: approved financial basis + imported work evidence. */
final readonly class AllocationPreview
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(
        private ProfileRegistry $profiles,
        private AllocationPolicyRegistry $policies,
        private WorkMetricsReader $workReader,
        private ActivityNormalizer $normalizer,
        private ApprovedReceipts $receipts,
    ) {
    }

    /**
     * @return array{profile:string,policy:string,metric:string,competence:string,receipt_sha256:string,allocation:AllocationResult}
     */
    public function calculate(Competencia $competence, string $json, string $metricId): array
    {
        $profile = $this->profiles->active();
        if ($profile->permitsLibreCodeSettlement()) {
            throw new LogicException(
                'O perfil LibreCode deve usar a apuração financeira homologada make:producao.'
            );
        }
        $receipts = $this->receipts->fromJson($json);
        $observations = $this->workReader->read($competence);
        $measurements = $this->normalizer->measure($observations, $metricId);
        $allocation = $this->policies->get($profile->allocationPolicyId())->allocate(
            $receipts,
            $measurements
        );
        return [
            'profile' => $profile->id(),
            'policy' => $profile->allocationPolicyId(),
            'metric' => $metricId,
            'competence' => (string) $competence,
            'receipt_sha256' => hash('sha256', $json),
            'allocation' => $allocation,
        ];
    }
}
