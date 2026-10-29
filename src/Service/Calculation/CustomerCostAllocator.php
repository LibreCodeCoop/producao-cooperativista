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
     * Locate client-specific costs that have no positive eligible client
     * receipt in the period. Report them for reconciliation, but do not
     * silently reallocate or defer costs without an approved policy.
     *
     * @param array<int|string, array<string, mixed>> $invoices
     * @param array<string, float> $costs
     * @return array<string, float>
     */
    public function unmatchedCosts(array $invoices, array $costs): array
    {
        $customersWithRevenue = [];
        foreach ($invoices as $invoice) {
            if ((float) ($invoice['amount'] ?? 0) > 0) {
                $customersWithRevenue[(string) ($invoice['customer_reference'] ?? '')] = true;
            }
        }

        return array_diff_key($costs, $customersWithRevenue);
    }

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
            // Apportion real cents by invoice instead of placing a floating
            // point residue on the last invoice (order-dependent).
            $parts = (new DecimalAllocation())->allocate($cost, $amounts);
            foreach ($parts as $key => $share) {
                $allocated[$key] = $share;
            }
        }
        return $allocated;
    }
}
