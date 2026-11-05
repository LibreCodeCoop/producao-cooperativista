<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Provider\Akaunting;

use DomainException;

/**
 * Consume metadata embedded by Field Studio in Akaunting's native API.
 * No additional HTTP requests or free-text parsing are needed.
 */
class FieldStudio
{
    private const array FIELDS = [
        'transaction_of_month', 'discount_percentage', 'customer_cnpj',
        'sector', 'archive', 'nfse', 'production_metadata_migrated',
    ];

    /**
     * @param list<array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    public function fromRecords(string $type, array $records, int $companyId): array
    {
        if (!in_array($type, ['invoice', 'bill', 'transaction'], true) || $companyId < 1) {
            throw new DomainException('Invalid company or Field Studio entity type.');
        }
        $result = [];
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1 || (isset($record['company_id']) && (int) $record['company_id'] !== $companyId)
                || ($type !== 'transaction' && ($record['type'] ?? null) !== $type)) {
                throw new DomainException('Akaunting returned a record outside the requested company or entity type.');
            }
            $fields = $record['field_studio'] ?? null;
            if (!is_array($fields) || array_is_list($fields)) {
                throw new DomainException(sprintf('Missing Field Studio API data for %s #%d.', $type, $id));
            }
            foreach (self::FIELDS as $key) {
                if (!isset($fields[$key]) || !is_array($fields[$key])
                    || !array_key_exists('is_set', $fields[$key])) {
                    throw new DomainException(sprintf('Field Studio schema incomplete: %s on %s.', $key, $type));
                }
            }
            $marker = $fields['production_metadata_migrated'];
            if (($marker['is_set'] ?? false) !== true || (string) ($marker['value'] ?? '') !== '1') {
                throw new DomainException(sprintf(
                    'Record %s #%d is not migrated to Field Studio; the competence cannot be processed.',
                    $type,
                    $id
                ));
            }
            if (isset($result[$id])) {
                throw new DomainException('Duplicate Akaunting record in Field Studio response.');
            }
            $result[$id] = $this->normalize($fields, $type, $id);
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function normalize(array $fields, string $type, int $id): array
    {
        $values = [];
        foreach ([
            'transaction_of_month', 'discount_percentage', 'customer_cnpj',
            'sector', 'archive', 'nfse',
        ] as $key) {
            $field = $fields[$key];
            if (($field['is_set'] ?? false) !== true) {
                continue;
            }
            $value = $field['value'] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new DomainException(sprintf('Invalid Field Studio value %s for %s #%d.', $key, $type, $id));
            }
            switch ($key) {
                case 'transaction_of_month':
                    if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value)) {
                        throw new DomainException('Invalid structured financial month.');
                    }
                    $values['transaction_of_month'] = $value;
                    break;
                case 'discount_percentage':
                    if (!preg_match('/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,6})?$/D', $value)
                        || (float) $value > 100) {
                        throw new DomainException('Invalid structured fixed discount percentage.');
                    }
                    $values['discount_percentage'] = $value;
                    break;
                case 'customer_cnpj':
                    if (!preg_match('/^[0-9]{14}$/D', $value)) {
                        throw new DomainException('Invalid structured customer CNPJ.');
                    }
                    $values['customer'] = $value;
                    break;
                case 'sector':
                    $values['sector'] = strtolower(trim($value));
                    break;
                case 'archive':
                    if (!in_array($value, ['0', '1'], true)) {
                        throw new DomainException('Invalid structured archive flag.');
                    }
                    $values['archive'] = $value === '1' ? 'sim' : 'nao';
                    break;
                case 'nfse':
                    if (!preg_match('/^[0-9]+$/D', $value)) {
                        throw new DomainException('Invalid structured NFS-e reference.');
                    }
                    $values['nfse'] = $value;
                    break;
            }
        }
        return $values;
    }
}
