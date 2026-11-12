<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only own-member financial view. The authenticated Akaunting user and
 * company come exclusively from server-side middleware, not request parameters.
 */
final class MemberRead
{
    public function history(mixed $from = null, mixed $to = null): array
    {
        abort_unless(auth()->check(), 401);
        $userId = (int) auth()->id();
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0 && $userId > 0, 403);

        // Defense in depth: company.identify already checks this, but a signed
        // request must never bypass explicit membership in this endpoint.
        abort_unless(DB::table('user_companies')->where('user_id', $userId)
            ->where('company_id', $companyId)->exists(), 403);

        $links = DB::table('lcc_pdc_member_links')
            ->where('company_id', $companyId)
            ->where('akaunting_user_id', $userId)
            ->where('state', 'active')
            ->limit(2)->get();

        abort_unless($links->count() === 1, 403);
        $link = $links->first();
        $verifiedAt = strtotime((string) $link->verified_at);
        $expiresAt = strtotime((string) $link->expires_at);
        abort_unless((int) $link->kimai_user_id > 0
            && trim((string) $link->nextcloud_user_id) !== ''
            && preg_match('/^[a-fA-F0-9]{64}$/D', (string) $link->source_revision_sha256) === 1
            && $verifiedAt !== false && $verifiedAt <= time()
            && $expiresAt !== false && $expiresAt > time(), 409);

        abort_unless(DB::table('contacts')
            ->where('company_id', $companyId)
            ->where('id', (int) $link->akaunting_contact_id)
            ->where('type', 'vendor')
            ->whereNull('deleted_at')->exists(), 403);

        try {
            $filters = MemberHistoryFilters::parse($from, $to);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid worked-competence filter.');
        }

        // Choose the latest append-only revision per month before limiting
        // results, so frequent revisions cannot hide older worked months.
        $latestIds = DB::table('lcc_pdc_member_statements')
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->where('akaunting_contact_id', (int) $link->akaunting_contact_id)
            ->groupBy('worked_competence');

        $query = DB::table('lcc_pdc_member_statements')
            ->where('company_id', $companyId)
            ->where('akaunting_contact_id', (int) $link->akaunting_contact_id)
            ->whereIn('id', $latestIds);

        if ($filters['from'] !== null) {
            $query->where('worked_competence', '>=', $filters['from']);
        }
        if ($filters['to'] !== null) {
            $query->where('worked_competence', '<=', $filters['to']);
        }

        $rows = $query->orderByDesc('worked_competence')->limit(120)->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'worked_competence' => $row->worked_competence,
                'financial_competence' => $row->financial_competence,
                'payment_competence' => $row->payment_competence,
                'status' => $row->status,
                'observed_at' => $row->observed_at,
                'hours' => ['observed_seconds' => $row->observed_seconds === null
                    ? null : (int) $row->observed_seconds],
                'money' => [
                    'eligible_production_cents' => $this->cents($row->eligible_production_cents),
                    'approved_gross_cents' => $this->cents($row->approved_gross_cents),
                    'deductions_cents' => $this->cents($row->deductions_cents),
                    'approved_net_cents' => $this->cents($row->approved_net_cents),
                    'billed_cents' => $this->cents($row->billed_cents),
                    'paid_cents' => $this->cents($row->paid_cents),
                ],
            ];
        }

        return ['schema_version' => 1, 'filters' => $filters, 'data' => $result];
    }

    private function cents(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
