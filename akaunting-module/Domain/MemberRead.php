<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain;

use Illuminate\Support\Facades\DB;

/**
 * Read-only own-member financial view. The authenticated Akaunting user and
 * company come exclusively from server-side middleware, not request parameters.
 */
final class MemberRead
{
    public function history(): array
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
        abort_unless((string) $link->source_revision_sha256 !== ''
            && strtotime((string) $link->expires_at) > time(), 409);

        abort_unless(DB::table('contacts')
            ->where('company_id', $companyId)
            ->where('id', (int) $link->akaunting_contact_id)
            ->where('type', 'vendor')
            ->whereNull('deleted_at')->exists(), 403);

        $rows = DB::table('lcc_pdc_member_statements')
            ->where('company_id', $companyId)
            ->where('akaunting_contact_id', (int) $link->akaunting_contact_id)
            ->orderByDesc('worked_competence')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(120)->get();

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->worked_competence])) {
                continue;
            }
            $seen[$row->worked_competence] = true;
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

        return ['schema_version' => 1, 'data' => $result];
    }

    private function cents(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
