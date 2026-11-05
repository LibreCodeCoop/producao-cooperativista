<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

use App\Provider\Akaunting\Dataset;
use DomainException;

/**
 * Include records assigned to a financial month even when the date used by
 * Akaunting's native date filter is in another month. Never edit source data.
 */
final class FinancialMonthScope
{
    private const int MAX_IDS = 200;
    private const int BATCH_SIZE = 50;

    /**
     * @param list<array<string,mixed>> $dateFiltered
     * @return list<array<string,mixed>>
     */
    public function complete(
        Dataset $dataset,
        array $dateFiltered,
        string $type,
        string $financialMonth,
        int $companyId,
        ?int $categoryId = null,
    ): array {
        if (!in_array($type, ['invoice', 'bill', 'transaction'], true)
            || !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $financialMonth)
            || $companyId < 1) {
            throw new DomainException('Invalid financial month lookup scope.');
        }
        $ids = $dataset->recordIds($type, $financialMonth, $companyId, self::MAX_IDS);
        // The Field Studio endpoint currently has no cursor. Fail safely if
        // this cap is reached rather than silently dropping financial records.
        if (count($ids) >= self::MAX_IDS) {
            throw new DomainException('O limite de registros por competência do Field Studio foi atingido; pagine a consulta antes de importar.');
        }
        $existing = [];
        foreach ($dateFiltered as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id <= 0 || isset($existing[$id])) {
                throw new DomainException('Invalid or duplicated record from date-filtered Akaunting API.');
            }
            $existing[$id] = $record;
        }
        $missing = [];
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new DomainException('Field Studio returned an invalid financial-month record ID.');
            }
            if (!isset($existing[$id])) {
                $missing[$id] = true;
            }
        }
        foreach (array_chunk(array_keys($missing), self::BATCH_SIZE) as $chunk) {
            $search = ($type !== 'transaction' ? 'type:' . $type . ' ' : '')
                . 'id:' . implode(',', $chunk);
            if ($categoryId !== null) {
                $search .= ' category_id:' . $categoryId;
            }
            $rows = $dataset->list($type === 'transaction' ? '/api/transactions' : '/api/documents', [
                'company_id' => $companyId,
                'search' => $search,
                'include_field_studio' => '1',
            ]);
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $field = $row['field_studio']['transaction_of_month'] ?? null;
                if (!isset($missing[$id]) || isset($existing[$id])
                    || ($type !== 'transaction' && ($row['type'] ?? null) !== $type)
                    || (isset($row['company_id']) && (int) $row['company_id'] !== $companyId)
                    || !is_array($field) || ($field['is_set'] ?? false) !== true
                    || ($field['value'] ?? null) !== $financialMonth) {
                    throw new DomainException('Inconsistent financial-month lookup returned by Akaunting.');
                }
                if ($categoryId !== null && (int) ($row['category_id'] ?? 0) !== $categoryId) {
                    throw new DomainException('Akaunting returned a transaction outside the requested category.');
                }
                $existing[$id] = $row;
                unset($missing[$id]);
            }
        }
        if ($missing !== [] && $categoryId === null) {
            throw new DomainException('Some financial-month records could not be fetched by ID.');
        }

        return array_values($existing);
    }
}
