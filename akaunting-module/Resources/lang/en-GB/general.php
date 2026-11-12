<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    'name' => 'Cooperative production',
    'description' => 'Production statements and member payments',
    'member_history' => 'My production history',
    'private_history_notice' => 'Only your own recorded financial statements are displayed. Estimates do not represent approved payables.',
    'paid_chart' => 'Payments by worked month',
    'chart_explanation' => 'Only recorded payment values are charted. Missing values are not treated as zero.',
    'no_confirmed_payments' => 'No payment amounts are available for this history.',
    'payment_for' => 'Payment for',
    'paid' => 'Paid amount',
    'worked_competence' => 'Worked month',
    'financial_competence' => 'Financial month',
    'payment_competence' => 'Payment month',
    'observed_hours' => 'Recorded hours',
    'last_source_update' => 'Source last updated',
    'unavailable' => 'Not available',
    'no_history' => 'No production statements are available for this account.',
    'statuses' => [
        'observed' => 'Recorded, not approved',
        'estimated' => 'Estimate, not approved',
        'approved' => 'Approved, not necessarily paid',
        'billed' => 'Billed, not necessarily paid',
        'partially_paid' => 'Partially paid',
        'paid' => 'Paid',
        'unavailable' => 'Unavailable',
    ],
    'money' => [
        'eligible_production_cents' => 'Eligible production',
        'approved_gross_cents' => 'Approved gross',
        'deductions_cents' => 'Deductions',
        'approved_net_cents' => 'Approved net',
        'billed_cents' => 'Billed',
        'paid_cents' => 'Paid',
    ],
];
