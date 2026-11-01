<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use DomainException;
use Impostos\Service\INSS;
use Impostos\Service\IRPF;

/**
 * Read-only financial capacity indicator. Never changes the current
 * cooperative distribution or creates a payment in Akaunting.
 */
final class NetWithdrawalGoal
{
    /**
     * @param list<array<string,mixed>> $members
     * @return array{net_target:float,reference_hours:int,required_pool:float,available_pool:float,shortfall:float,potential_surplus:float,members:int,mode:string}
     */
    public function evaluate(array $members, int $year, int $month): array
    {
        $netTarget = self::target();
        $hoursReference = self::referenceHours();
        $required = 0.0;
        $available = 0.0;
        $participating = 0;
        foreach ($members as $row) {
            $available += (float) ($row['base_producao'] ?? 0);
            $seconds = (float) ($row['hours_seconds'] ?? 0);
            $taxNumber = preg_replace('/\D+/', '', (string) ($row['tax_number'] ?? ''));
            if ($seconds <= 0 || $taxNumber === null || strlen($taxNumber) !== 11) {
                continue;
            }
            ++$participating;
            $hours = $seconds / 3600;
            $goal = $netTarget * $hours / $hoursReference;
            $required += $this->poolRequired($goal, $year, $month, (int) ($row['dependentes'] ?? 0));
        }

        return [
            'net_target' => $netTarget,
            'reference_hours' => $hoursReference,
            'required_pool' => round($required, 2),
            'available_pool' => round($available, 2),
            'shortfall' => round(max(0.0, $required - $available), 2),
            'potential_surplus' => round(max(0.0, $available - $required), 2),
            'members' => $participating,
            'mode' => 'simulation_only',
        ];
    }

    private static function target(): float
    {
        $raw = getenv('PRODUCAO_META_LIQUIDA_MENSAL');
        $value = $raw === false || $raw === '' ? '50000' : $raw;
        if (!is_numeric($value) || !is_finite((float) $value)
            || (float) $value <= 0 || (float) $value > 1000000) {
            throw new DomainException('Meta líquida mensal deve ser positiva e até R$ 1 milhão.');
        }
        return (float) $value;
    }

    private static function referenceHours(): int
    {
        $raw = getenv('PRODUCAO_META_HORAS_REFERENCIA');
        $value = $raw === false || $raw === '' ? '165' : $raw;
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 744) {
            throw new DomainException('Referência mensal de horas inválida (1 a 744).');
        }
        return (int) $value;
    }

    private function poolRequired(float $net, int $year, int $month, int $dependents): float
    {
        $low = 0.0;
        $high = max(1.0, $net * 2);
        while ($this->netFromPool($high, $year, $month, $dependents) < $net) {
            $high *= 2;
            if ($high > 100000000.0) {
                throw new DomainException('Meta de retirada fora da capacidade de simulação.');
            }
        }
        for ($i = 0; $i < 40; ++$i) {
            $mid = ($high + $low) / 2;
            if ($this->netFromPool($mid, $year, $month, $dependents) >= $net) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }
        return ceil($high * 100 - 0.000001) / 100;
    }

    private function netFromPool(float $pool, int $year, int $month, int $dependents): float
    {
        // These are the same calculations used by Values for PF, excluding
        // individual advances/discounts that do not represent monthly earnings.
        $amount = static fn (float $value): float => DecimalAllocation::amount(DecimalAllocation::cents($value));
        $aux = $amount($pool * 0.20);
        $frra = $amount($pool / 12);
        $gross = $amount($pool - $aux - $frra);
        $inss = $amount(new INSS($year)->calcula($gross));
        $irpf = new IRPF($year, $month);
        $taxBase = $amount($irpf->calculaBase($gross, $inss, $dependents));
        $tax = $amount($irpf->calcula($taxBase, $dependents));

        return $amount($gross + $aux - $inss - $tax);
    }
}
