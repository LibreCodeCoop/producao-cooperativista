<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;

/**
 * Pure transformation stage. Only pre-classified, competence-specific income
 * and work rows can enter the computation. No bank-account balance or API I/O.
 *
 * Preserves the existing fixed-vs-variable administrative discount rules.
 */
final readonly class ProductionDistributionPlanner
{
    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(private WeightedAllocator $allocator)
    {
    }

    /**
     * @param array<int|string, array<string, mixed>> $incomes Eligible client receipts
     * @param list<array<string, mixed>> $work Hours and weights from the same competence
     */
    public function plan(array $incomes, array $work): ProductionDistributionPlan
    {
        $fixedCustomers = [];
        $variableCustomers = [];
        $fixed = [];
        $fixedAmount = 0.0;
        $variableAmount = 0.0;
        foreach ($incomes as $income) {
            $customer = (string) ($income['customer_reference'] ?? '');
            $value = $income['base_producao'] ?? null;
            if ($customer === '' || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
                throw new DomainException('Receita elegível com cliente ausente ou valor inválido. Confira a apuração da competência.');
            }
            if (($income['percentual_desconto_fixo'] ?? false) === true) {
                $fixedCustomers[$customer] = true;
                $fixed[] = $income;
                $fixedAmount += (float) $value;
            } else {
                $variableCustomers[$customer] = true;
                $variableAmount += (float) $value;
            }
        }
        if (array_intersect_key($fixedCustomers, $variableCustomers) !== []) {
            throw new DomainException(
                'O mesmo cliente possui entradas com percentuais administrativos fixos e móveis. Confira a classificação antes de distribuir.'
            );
        }

        $byCustomer = [];
        $variableRows = [];
        $contracted = [];
        $worked = [];
        foreach ($work as $row) {
            $customer = (string) ($row['customer_reference'] ?? '');
            $byCustomer[$customer][] = $row;
            if (!isset($fixedCustomers[$customer])) {
                $variableRows[] = $row;
                $duration = (float) ($row['trabalhado'] ?? 0);
                $contracted[$customer] ??= (float) ($row['total_cliente'] ?? 0);
                $worked[$customer] = ($worked[$customer] ?? 0) + $duration;
            }
        }

        $amounts = [];
        foreach ($fixed as $row) {
            $customer = (string) $row['customer_reference'];
            $context = sprintf('Cliente %s (desconto fixo)', $customer);
            $weights = $this->allocator->weightsFromRows($byCustomer[$customer] ?? [], $context);
            foreach ($this->allocator->allocate((float) $row['base_producao'], $weights, $context) as $member => $amount) {
                $amounts[$member] = ($amounts[$member] ?? 0) + $amount;
            }
        }

        $variableWeights = $this->allocator->weightsFromRows($variableRows, 'Produção cooperativista');
        $contractedSeconds = array_sum($contracted);
        $workedSeconds = array_sum($worked);
        $toDistribute = $variableAmount;
        if ($contractedSeconds > 0 && $workedSeconds < $contractedSeconds) {
            $toDistribute *= $workedSeconds / $contractedSeconds;
        }
        foreach ($this->allocator->allocate($toDistribute, $variableWeights, 'Produção cooperativista') as $member => $amount) {
            $amounts[$member] = ($amounts[$member] ?? 0) + $amount;
        }

        return new ProductionDistributionPlan(
            $amounts,
            $variableWeights,
            $fixedAmount,
            $variableAmount,
            $toDistribute,
        );
    }
}
