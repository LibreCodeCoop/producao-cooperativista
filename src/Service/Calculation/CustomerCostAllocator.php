<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;

/**
 * Allocates the existing customer-specific expense pool across every invoice
 * of that customer, independently of SQL return order. The eligible amount is
 * still calculated from classified period income minus expenses, not cash in bank.
 */
final class CustomerCostAllocator
{
    /**
     * @param array<int|string, array<string, mixed>> $invoices
     * @param array<string, float> $costs
     * @return array<int|string, float> Expense assigned to each invoice.
     */
    public function allocate(array $invoices, array $costs): array
    {
        $grouped = [];
        foreach ($invoices as $key => $row) {
            $customer = (string) ($row['customer_reference'] ?? '');
            $amount = (float) ($row['amount'] ?? 0);
            if (!is_finite($amount) || $amount < 0) {
                throw new DomainException('Fatura de cliente com valor inválido. Revise a movimentação importada.');
            }
            $grouped[$customer][$key] = $amount;
        }

        $allocated = [];
        foreach ($grouped as $customer => $amounts) {
            $total = array_sum($amounts);
            $cost = (float) ($costs[$customer] ?? 0.0);
            if (!is_finite($cost) || $cost < 0) {
                throw new DomainException('Dispêndios de cliente com valor inválido. Confira custos da competência.');
            }
            if ($cost > $total + 0.000001) {
                throw new DomainException(sprintf(
                    'Custos do cliente %s (R$ %.2f) excedem receitas elegíveis (R$ %.2f). Revise custos, documentos e competência.',
                    $customer ?: '(não identificado)',
                    $cost,
                    $total
                ));
            }
            $assigned = 0.0;
            $positive = array_keys(array_filter($amounts, static fn (float $value): bool => $value > 0));
            $last = $positive === [] ? null : end($positive);
            foreach ($amounts as $key => $amount) {
                $share = ($amount > 0 && $total > 0)
                    ? ($key === $last ? $cost - $assigned : $cost * $amount / $total)
                    : 0.0;
                $allocated[$key] = $share;
                $assigned += $share;
            }
        }
        return $allocated;
    }
}
