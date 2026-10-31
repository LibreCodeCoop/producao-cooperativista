<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Integration\MemberParameterValidator;
use PHPUnit\Framework\TestCase;

final class SourceConfigurationValidationTest extends TestCase
{
    public function testBrazilianTaxIdWeightAndDependentsAreValidatedBeforeApiWrites(): void
    {
        $validator = new MemberParameterValidator();
        self::assertSame('529' . '982' . '247' . '25', $validator->validate('tax_number', '529' . '982' . '247' . '25'));
        self::assertSame(2.75, $validator->validate('weight', '2,75'));
        self::assertSame(0.0001, $validator->validate('weight', '0,0001'));
        self::assertSame(5.0, $validator->validate('weight', '5'));
        self::assertSame(4.75, $validator->validate('weight', '4,75'));
        self::assertSame(0, $validator->validate('dependents', '0'));
    }

    public function testRejectsInvalidCpfAndRepeatedNumbers(): void
    {
        $validator = new MemberParameterValidator();
        foreach (['111' . '111' . '111' . '11', '529' . '982' . '247' . '24', 'abc'] as $bad) {
            try {
                $validator->validate('tax_number', $bad);
                self::fail('Invalid CPF was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsNegativeOrExcessiveWeights(): void
    {
        $validator = new MemberParameterValidator();
        foreach (['0', '-1', '1.00000', '1e999', '999999', '5,0001', '6', '999', '1,12345', '0,0000'] as $bad) {
            try {
                $validator->validate('weight', $bad);
                self::fail('Invalid weight was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
