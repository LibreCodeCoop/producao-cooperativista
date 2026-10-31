<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use Impostos\Service\IRPF;
use PHPUnit\Framework\TestCase;

final class Tax2026ReferenceTest extends TestCase
{
    public function testOfficialTaxAuthorityExampleForFiveThousand(): void
    {
        $irpf = new IRPF(2026, 1);
        $base = $irpf->calculaBase(5000.0, 509.60, 0);
        self::assertEqualsWithDelta(4392.80, $base, 0.001);
        self::assertSame(0.0, $irpf->calcula($base, 0));
    }

    public function testOfficialTaxAuthorityExampleForSixThousand(): void
    {
        $irpf = new IRPF(2026, 1);
        $base = $irpf->calculaBase(6000.0, 649.60, 0);
        self::assertEqualsWithDelta(5350.40, $base, 0.001);
        self::assertEqualsWithDelta(382.88, $irpf->calcula($base, 0), 0.005);
    }

    public function testNoReductionAboveSevenThousandThreeHundredFifty(): void
    {
        $irpf = new IRPF(2026, 10);
        $base = $irpf->calculaBase(7500.0, 825.00, 0);
        self::assertEqualsWithDelta(6675.00, $base, 0.001);
        self::assertEqualsWithDelta(926.895, $irpf->calcula($base, 0), 0.005);
    }
}
