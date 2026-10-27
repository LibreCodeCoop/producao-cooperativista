<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

/** Single list of ETL-owned working tables, ordered for safe deletions. */
final class StagingSchema
{
    public const array TABLES = [
        'timesheet',
        'transactions',
        'invoices',
        'nfse',
        'taxes',
        'categories',
        'users',
        'projects',
        'customers',
    ];
}
