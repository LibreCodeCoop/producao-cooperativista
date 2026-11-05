<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Simulate Akaunting's opt-in Field Studio response to a document write.
 */
final class FieldStudioResponse
{
    /** @param array<string, mixed> $body */
    public static function fromRequest(array $body): array
    {
        $custom = $body['custom_fields'] ?? [];
        $fields = [];
        foreach ([
            'transaction_of_month', 'discount_percentage', 'customer_cnpj',
            'sector', 'archive', 'nfse',
        ] as $key) {
            $isSet = array_key_exists($key, $custom);
            $fields[$key] = [
                'value' => $isSet ? (string) $custom[$key] : null,
                'is_set' => $isSet,
            ];
        }
        return $fields;
    }
}
