<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Akaunting\Document\Values;
use App\Service\Calculation\DecimalAllocation;
use App\Service\Cooperado;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CooperativeSettlementDecimalTest extends TestCase
{
    public function testFrraAndAssistanceCloseToExactCents(): void
    {
        $cooperado = new ReflectionClass(Cooperado::class)->newInstanceWithoutConstructor();
        $cooperado->setTaxNumber('00000000000');
        $cooperado->setDependentes(0);
        $values = new Values(2026, 1, $cooperado);
        $values->setBaseProducao(100.01);

        self::assertSame(8.33, $values->getFrra());
        self::assertSame(20.0, $values->getAuxilio());
        self::assertSame(71.68, $values->getBruto());
        self::assertSame(10001, DecimalAllocation::cents($values->getBruto())
            + DecimalAllocation::cents($values->getFrra())
            + DecimalAllocation::cents($values->getAuxilio()));
        self::assertSame(
            DecimalAllocation::cents($values->getBruto())
                - DecimalAllocation::cents($values->getInss())
                - DecimalAllocation::cents($values->getIrpf())
                + DecimalAllocation::cents($values->getAuxilio()),
            DecimalAllocation::cents($values->getLiquido()),
        );
    }

    public function testLegalEntityHasNoAutomaticIndividualProvisions(): void
    {
        $cooperado = new ReflectionClass(Cooperado::class)->newInstanceWithoutConstructor();
        $cooperado->setTaxNumber('00000000000000');
        $values = new Values(2026, 1, $cooperado);
        $values->setBaseProducao(100.015);

        self::assertSame(100.02, $values->getBruto());
        self::assertSame(0.0, $values->getFrra());
        self::assertSame(0.0, $values->getAuxilio());
        self::assertSame(100.02, $values->getLiquido());
    }
}
