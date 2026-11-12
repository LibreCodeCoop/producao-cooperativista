<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Append-only encrypted source snapshots. This internal-only service accepts
 * trusted backend import decisions, not arbitrary user/API input.
 */
final class SnapshotStore
{
    /**
     * @param array<string, mixed> $payload
     * @param array{company_id:int, worked_competence:string, financial_competence:string,
     *     payment_competence:string, source:string, external_revision:string,
     *     schema_version:string, source_sha256:string, collected_at:string} $meta
     */
    public function capture(array $meta, array $payload): int
    {
        $this->validate($meta);
        $encoded = json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $digest = hash('sha256', $encoded);
        $company = $meta['company_id'];

        return DB::transaction(static function () use ($meta, $encoded, $digest, $company): int {
            $key = [
                'company_id' => $company,
                'worked_competence' => $meta['worked_competence'],
                'source' => $meta['source'],
                'external_revision' => $meta['external_revision'],
            ];
            $existing = DB::table('lcc_pdc_snapshots')->where($key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->payload_sha256, $digest)
                    || !hash_equals((string) $existing->source_sha256, $meta['source_sha256'])
                    || $existing->financial_competence !== $meta['financial_competence']
                    || $existing->payment_competence !== $meta['payment_competence']) {
                    throw new DomainException('Source revision conflict: preserve the prior snapshot for reconciliation.');
                }

                return (int) $existing->id;
            }

            // Laravel Crypt uses the instance-specific application encryption key.
            // Never log or persist unencrypted snapshot payloads.
            return (int) DB::table('lcc_pdc_snapshots')->insertGetId([
                ...$key,
                'financial_competence' => $meta['financial_competence'],
                'payment_competence' => $meta['payment_competence'],
                'schema_version' => $meta['schema_version'],
                'source_sha256' => $meta['source_sha256'],
                'payload_sha256' => $digest,
                'encrypted_payload' => Crypt::encryptString($encoded),
                'collected_at' => $meta['collected_at'],
                'imported_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function readForCompany(int $companyId, int $snapshotId): array
    {
        if ($companyId <= 0 || $snapshotId <= 0) {
            throw new DomainException('Invalid snapshot read identity.');
        }
        $row = DB::table('lcc_pdc_snapshots')
            ->where('company_id', $companyId)
            ->where('id', $snapshotId)
            ->first();

        if ($row === null) {
            throw new DomainException('Source snapshot does not exist for this company.');
        }

        $plaintext = Crypt::decryptString((string) $row->encrypted_payload);
        if (!hash_equals((string) $row->payload_sha256, hash('sha256', $plaintext))) {
            throw new RuntimeException('Snapshot fingerprint mismatch: stop and reconcile.');
        }

        $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid stored snapshot payload.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $value
     *  @return array<string,mixed>
     */
    private function normalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        return $value;
    }

    /** @param array<string,mixed> $meta */
    private function validate(array $meta): void
    {
        if (!isset($meta['company_id']) || !is_int($meta['company_id']) || $meta['company_id'] <= 0) {
            throw new DomainException('Snapshot requires a positive verified company ID.');
        }

        foreach (['worked_competence', 'financial_competence', 'payment_competence'] as $key) {
            if (!isset($meta[$key]) || !is_string($meta[$key])
                || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $meta[$key]) !== 1) {
                throw new DomainException('Snapshot requires three explicit valid competences.');
            }
        }

        if (!in_array($meta['source'] ?? null, ['kimai', 'nextcloud', 'akaunting'], true)
            || !is_string($meta['external_revision'] ?? null)
            || $meta['external_revision'] === ''
            || strlen($meta['external_revision']) > 128
            || !is_string($meta['schema_version'] ?? null)
            || $meta['schema_version'] === ''
            || strlen($meta['schema_version']) > 40
            || !is_string($meta['source_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $meta['source_sha256']) !== 1) {
            throw new DomainException('Invalid snapshot source provenance.');
        }

        if (!is_string($meta['collected_at'] ?? null)
            || \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $meta['collected_at']) === false) {
            throw new DomainException('Snapshot capture time must be explicit.');
        }
    }
}
