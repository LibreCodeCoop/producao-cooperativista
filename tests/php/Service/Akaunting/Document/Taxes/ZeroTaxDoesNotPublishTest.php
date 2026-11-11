<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Document\Taxes;

use App\Entity\Producao\Tax as TaxEntity;
use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\Taxes\Cofins;
use App\Service\Akaunting\Document\Taxes\IrpfRetidoNaNota;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZeroTaxDoesNotPublishTest extends TestCase
{
    /** @return array<string, array{class-string<ZeroCofinsSpy|ZeroIrpfRetidoSpy>}> */
    public static function zeroTaxScenarios(): array
    {
        return [
            'Cofins payable zero' => [ZeroCofinsSpy::class],
            'IRPF retained zero' => [ZeroIrpfRetidoSpy::class],
        ];
    }

    /** @param class-string<ZeroCofinsSpy|ZeroIrpfRetidoSpy> $class */
    #[DataProvider('zeroTaxScenarios')]
    public function testZeroAmountDoesNotAttemptToPublishDocument(string $class): void
    {
        $taxEntity = new TaxEntity();
        $taxEntity->fromArray([
            'id' => 1, 'name' => 'Taxa teste', 'enabled' => 1, 'rate' => 0.0, 'metadata' => [],
        ]);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($taxEntity);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);

        $http = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['send'])
            ->getMock();
        $http->expects(self::never())->method('send');

        $dates = new Dates('br-rj', 12, new DateTime('2026-10-07'));
        $dates->setInicio(new DateTime('2026-09-01'));

        $document = new $class(
            entityManager: $manager,
            dates: $dates,
            documents: $this->createStub(Documents::class),
            request: $http,
            documentConfiguration: new DocumentConfiguration(),
        );
        if ($document instanceof ZeroCofinsSpy) {
            $document->setTotalBrutoNotasClientes(0.0);
        }
        $document->saveMonthTaxes();

        self::assertSame(0, $document->saveCount, 'Zero tax must never create a document, including an empty invoice.');
    }
}

final class ZeroCofinsSpy extends Cofins
{
    public int $saveCount = 0;

    protected function coletaInvoiceNaoPago(): self
    {
        return $this;
    }

    protected function getTotalRetainedOfMonth(): float
    {
        return 0.0;
    }

    public function save(): self
    {
        ++$this->saveCount;
        return $this;
    }
}

final class ZeroIrpfRetidoSpy extends IrpfRetidoNaNota
{
    public int $saveCount = 0;

    protected function coletaInvoiceNaoPago(): self
    {
        return $this;
    }

    protected function getTotalRetainedOfMonth(): float
    {
        return 0.0;
    }

    public function save(): self
    {
        ++$this->saveCount;
        return $this;
    }
}
