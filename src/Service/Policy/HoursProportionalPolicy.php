<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use App\Service\Calculation\WeightedAllocator;
use DomainException;

/**
 * Neutral proportional-rateio example. Not the approved policy of any named
 * cooperative. Requires explicitly supplied and reviewed eligible amounts.
 */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class HoursProportionalPolicy implements AllocationPolicy
{
    public function __construct(private WeightedAllocator $allocator)
    {
    }

    #[\Override]
    public function id(): string
    {
        return 'hours-proportional';
    }

    #[\Override]
    public function allocate(array $receipts, array $measurements): AllocationResult
    {
        $eligibleByCustomer = [];
        foreach ($receipts as $receipt) {
            $customer = trim($receipt['customer_reference']);
            $amount = $receipt['amount'];
            if ($customer === '' || !is_finite($amount) || $amount < 0) {
                throw new DomainException('Base financeira inválida: cliente e valores não negativos são obrigatórios.');
            }
            $eligibleByCustomer[$customer] = ($eligibleByCustomer[$customer] ?? 0) + $amount;
        }

        $unitsByCustomer = [];
        foreach ($measurements as $measurement) {
            $customer = trim($measurement['customer_reference']);
            $member = trim($measurement['member_id']);
            $units = $measurement['units'];
            if ($customer === '' || $member === '' || !is_finite($units) || $units < 0) {
                throw new DomainException('Medição inválida: cliente, cooperado e unidades não negativas são obrigatórios.');
            }
            if ($units === 0.0) {
                continue;
            }
            $unitsByCustomer[$customer][$member] =
                ($unitsByCustomer[$customer][$member] ?? 0.0) + $units;
        }

        ksort($eligibleByCustomer, SORT_STRING);
        $totals = [];
        $details = [];
        foreach ($eligibleByCustomer as $customer => $amount) {
            $weights = $unitsByCustomer[$customer] ?? [];
            ksort($weights, SORT_STRING);
            $details[$customer] = $this->allocator->allocate(
                $amount,
                $weights,
                'Rateio proporcional do cliente ' . $customer
            );
            foreach ($details[$customer] as $member => $value) {
                $totals[$member] = ($totals[$member] ?? 0.0) + $value;
            }
        }
        ksort($totals, SORT_STRING);
        return new AllocationResult($totals, $details, array_sum($eligibleByCustomer));
    }
}
