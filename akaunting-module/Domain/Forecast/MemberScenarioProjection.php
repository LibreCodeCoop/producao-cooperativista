<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\CooperativeProduction\Domain\Calculation\DecimalAllocation;
use Modules\CooperativeProduction\Domain\Calculation\ProductionDistributionPlanner;

/** Pure scenario calculator; never performs I/O or authorizes its member key. */
final readonly class MemberScenarioProjection
{
    public function __construct(private ProductionDistributionPlanner $planner)
    {
    }

    /**
     * Source arrays and the member key MUST come from a trusted, already
     * authorized backend snapshot; never bind them from HTTP request bodies.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    public function project(string $memberKey, array $source, array $scenario, string $evaluatedAt): array
    {
        if (trim($memberKey) === '') {
            throw new InvalidArgumentException('A server-resolved member is required.');
        }
        $kind = $scenario['type'] ?? 'observed-only';
        if (!in_array($kind, ['observed-only', 'additional-hours', 'conditional-receipts'], true)) {
            throw new InvalidArgumentException('Unsupported forecast scenario.');
        }
        $evaluationTime = $this->utc($evaluatedAt);
        if ($evaluationTime === null) {
            throw new InvalidArgumentException('An explicit UTC evaluation timestamp is required.');
        }

        $sync = $this->utc($source['synchronized_at'] ?? null);
        $expiry = $this->utc($source['valid_until'] ?? null);
        $competence = $source['worked_competence'] ?? null;
        $revision = $source['public_revision'] ?? null;
        $response = [
            'schema_version' => 1,
            'scenario' => $kind,
            'state' => 'unavailable',
            'worked_competence' => is_string($competence) ? $competence : null,
            'synchronized_at' => $sync?->format('Y-m-d\TH:i:s\Z'),
            'source_version' => is_string($revision) && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $revision) === 1
                ? $revision : null,
            'assumptions' => [
                'receipt_basis' => $kind === 'conditional-receipts' ? 'includes_unreceived_if_verified' : 'confirmed_eligible_only',
                'hours_basis' => $kind === 'additional-hours' ? 'hypothetical_additional' : 'observed',
                'deductions' => 'not_projected',
            ],
            'money' => ['estimated_production_cents' => null, 'estimated_net_cents' => null],
            'limitations' => ['not_an_approval', 'not_a_payable', 'payment_not_guaranteed', 'deductions_not_verified'],
            'unavailable_reasons' => [],
        ];

        if (!is_string($competence)
            || preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $competence) !== 1
            || $sync === null || $expiry === null
            || $sync > $evaluationTime || $expiry <= $evaluationTime || $expiry <= $sync) {
            $response['unavailable_reasons'][] = 'snapshot_missing_or_stale';
            return $response;
        }
        $evidence = $source['evidence'] ?? [];
        if (!is_array($evidence)
            || ($evidence['cohort_complete'] ?? false) !== true
            || ($evidence['eligible_receipts_verified'] ?? false) !== true
            || ($evidence['identity_link_verified'] ?? false) !== true
            || !is_array($source['incomes'] ?? null)
            || !is_array($source['work'] ?? null)) {
            $response['unavailable_reasons'][] = 'source_evidence_incomplete';
            return $response;
        }

        $incomes = $source['incomes'];
        $work = $source['work'];
        if ($kind === 'additional-hours') {
            $seconds = $scenario['additional_seconds'] ?? null;
            $cap = $source['max_additional_seconds'] ?? null;
            $customer = $scenario['customer_reference'] ?? null;
            if (!is_int($seconds) || !is_int($cap) || $seconds < 0 || $cap < 0
                || $seconds > $cap || !is_string($customer) || $customer === '') {
                $response['unavailable_reasons'][] = 'unverified_hours_assumption';
                return $response;
            }
            $matched = false;
            foreach ($work as &$row) {
                if (($row['tax_number'] ?? null) !== $memberKey
                    || ($row['customer_reference'] ?? null) !== $customer) {
                    continue;
                }
                if (!is_numeric($row['trabalhado'] ?? null) || (float) $row['trabalhado'] < 0) {
                    break;
                }
                $row['trabalhado'] = (float) $row['trabalhado'] + $seconds;
                $matched = true;
                break;
            }
            unset($row);
            if (!$matched) {
                $response['unavailable_reasons'][] = 'member_customer_work_not_verified';
                return $response;
            }
            $response['limitations'][] = 'additional_hours_are_hypothetical';
        }
        if ($kind === 'conditional-receipts') {
            $pending = $source['conditional_incomes'] ?? null;
            if (($evidence['conditional_receipts_classified'] ?? false) !== true
                || !is_array($pending) || $pending === []) {
                $response['unavailable_reasons'][] = 'conditional_receipts_not_verified';
                return $response;
            }
            foreach ($pending as $receipt) {
                if (!is_array($receipt) || ($receipt['classification_verified'] ?? false) !== true
                    || ($receipt['is_received'] ?? null) !== false) {
                    $response['unavailable_reasons'][] = 'conditional_receipts_not_verified';
                    return $response;
                }
                $incomes[] = $receipt;
            }
            $response['limitations'][] = 'conditional_receipts_are_unreceived';
        }

        try {
            $plan = $this->planner->plan($incomes, $work);
            if (!array_key_exists($memberKey, $plan->amounts)) {
                $response['unavailable_reasons'][] = 'member_work_not_in_verified_snapshot';
                return $response;
            }
            $response['money']['estimated_production_cents'] = DecimalAllocation::cents($plan->amounts[$memberKey]);
        } catch (DomainException | InvalidArgumentException | \TypeError) {
            // Never expose raw financial or personal input in a public error.
            $response['unavailable_reasons'][] = 'calculation_inputs_invalid';
            return $response;
        }

        $response['state'] = $kind === 'conditional-receipts' ? 'conditional' : 'estimated';
        return $response;
    }

    private function utc(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date : null;
    }
}
