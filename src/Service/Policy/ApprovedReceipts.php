<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use DomainException;
use JsonException;

/**
 * Explicitly approved revenue bases supplied independently from the LibreCode
 * administrative/tax calculation. These are NOT inferred bank balances.
 */
final class ApprovedReceipts
{
    /**
     * @return list<array{customer_reference:string, amount:float}>
     */
    public function fromJson(string $json): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('O arquivo de bases aprovadas não contém JSON válido.', 0, $error);
        }
        if (!is_array($payload) || !array_is_list($payload) || $payload === []) {
            throw new DomainException('O arquivo de bases aprovadas deve conter uma lista não vazia de receitas por cliente.');
        }
        $result = [];
        foreach ($payload as $row) {
            if (!is_array($row) || !isset($row['customer_reference'], $row['eligible_amount'], $row['currency'])
                || !is_string($row['customer_reference'])
                || !is_string($row['currency']) || $row['currency'] !== 'BRL'
                || !is_numeric($row['eligible_amount'])) {
                throw new DomainException('Base de distribuição inválida. Informe cliente, eligible_amount e currency=BRL.');
            }
            $value = (float) $row['eligible_amount'];
            if (!is_finite($value) || $value < 0 || trim($row['customer_reference']) === '') {
                throw new DomainException('Base de distribuição inválida: valores não negativos e cliente identificado.');
            }
            $result[] = [
                'customer_reference' => trim($row['customer_reference']),
                'amount' => $value,
            ];
        }
        return $result;
    }
}
