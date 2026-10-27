<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\Competencia;
use DateTimeInterface;
use DomainException;

/**
 * Produces stable input fingerprints for one partition. The compact manifest
 * never contains financial amounts, names, tax numbers or credentials.
 */
final class InputManifestBuilder
{
    /**
     * @param array<string, iterable<array<string, mixed>>> $sources
     * @param array<string, int|float|string|bool|null> $rules No credentials
     * @return array<string, mixed>
     */
    public function build(
        Competencia $competence,
        array $sources,
        array $rules = [],
        string $revision = 'unknown',
    ): array {
        $digests = [];
        $weights = [];
        ksort($sources);
        foreach ($sources as $source => $rows) {
            $rowHashes = [];
            foreach ($rows as $row) {
                $rowHashes[] = $this->digest($row);
                if ($source === 'users') {
                    // Historical weights are *not* recovered from the current
                    // Kimai profile; record exactly which weights were observed.
                    $weights[] = $this->digest([
                        'id' => $row['id'] ?? null,
                        'peso' => $row['peso'] ?? null,
                    ]);
                }
            }
            sort($rowHashes, SORT_STRING);
            $digests[$source] = [
                'count' => count($rowHashes),
                'sha256' => hash('sha256', implode("\n", $rowHashes)),
            ];
        }
        sort($weights, SORT_STRING);
        ksort($rules);
        $summary = [
            'schema' => 'librecode.etl-input-manifest.v1',
            'competencia' => (string) $competence,
            'financial_month' => $competence->financialMonth(),
            'revision' => $revision,
            'sources' => $digests,
            'weights_sha256' => $this->digest($weights),
            'rules_sha256' => $this->digest($rules),
            'historical_weights' => 'current-users-snapshot-unversioned',
        ];
        $summary['sha256'] = $this->digest($summary);
        return $summary;
    }

    private function digest(mixed $value): string
    {
        $json = json_encode($this->normalize($value), JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return hash('sha256', $json);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_float($value) && !is_finite($value)) {
            throw new DomainException('Valor não finito entre os dados importados.');
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                $item = $this->normalize($item);
            }
            unset($item);
        }
        return $value;
    }
}
