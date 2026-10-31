<?php

declare(strict_types=1);

namespace App\Service\Integration;

/**
 * Validate the exact cooperative fields exposed for remote updates.
 * Shared by every API source; no calls or persistence.
 */
final class MemberParameterValidator
{
    public function validate(string $field, string $raw): string|int|float
    {
        $value = trim($raw);
        if ($field === 'tax_number') {
            $digits = preg_replace('/\\D/', '', $value);
            if (!is_string($digits) || !in_array(strlen($digits), [11, 14], true)
                || strlen($value) > 24 || !self::validTaxNumber($digits)) {
                throw new \InvalidArgumentException('CPF/CNPJ inválido. Informe um documento válido.');
            }
            return $digits;
        }
        if ($field === 'dependents') {
            if (!preg_match('/^(?:[0-9]|[12][0-9]|30)$/D', $value)) {
                throw new \InvalidArgumentException('Dependentes deve ser um inteiro de 0 a 30.');
            }
            return (int) $value;
        }
        if ($field === 'weight') {
            $value = str_replace(',', '.', $value);
            if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\\.[0-9]{1,4})?$/D', $value)
                || (float) $value <= 0 || (float) $value > 999) {
                throw new \InvalidArgumentException('Peso deve ser positivo, até 999, com até quatro casas decimais.');
            }
            return (float) $value;
        }
        throw new \InvalidArgumentException('Campo inválido.');
    }

    private static function validTaxNumber(string $digits): bool
    {
        $size = strlen($digits);
        if ($size !== 11 && $size !== 14) {
            return false;
        }
        if (preg_match('/^(\\d)\\1+$/D', $digits)) {
            return false;
        }
        if ($size === 11) {
            for ($position = 9; $position < 11; $position++) {
                $sum = 0;
                for ($i = 0; $i < $position; $i++) {
                    $sum += (int) $digits[$i] * ($position + 1 - $i);
                }
                $digit = ($sum * 10) % 11;
                if (($digit === 10 ? 0 : $digit) !== (int) $digits[$position]) {
                    return false;
                }
            }
            return true;
        }
        foreach ([12, 13] as $position) {
            $weights = $position === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digits[$i] * ($weights[$i] ?? throw new \LogicException('Índice inválido no cálculo de verificação do CNPJ.'));
            }
            if (($sum % 11 < 2 ? 0 : 11 - $sum % 11) !== (int) $digits[$position]) {
                return false;
            }
        }
        return true;
    }

}
