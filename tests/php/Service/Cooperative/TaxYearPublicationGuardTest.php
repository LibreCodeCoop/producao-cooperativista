<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Cooperative;

use App\Service\Cooperative\LibreCode\LibreCodeAkauntingPublisher;
use App\Service\Cooperative\LibreCode\TaxYearPublicationGuard;
use App\Helper\Dates;
use App\Service\Cooperado;
use App\Service\Akaunting\Source\Documents;
use App\Service\Akaunting\DocumentConfiguration;
use App\Provider\Akaunting\Request;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
use DateTimeImmutable;
use DateTime;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaxYearPublicationGuardTest extends KernelTestCase
{
    #[DataProvider('yearBoundaryExamples')]
    public function testCrossYearTaxReferenceBlocksPublication(string $work, string $payment): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Publicação fiscal bloqueada');
        new TaxYearPublicationGuard()->assertSafeForIndividual(
            new DateTimeImmutable($work),
            new DateTimeImmutable($payment),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function yearBoundaryExamples(): array
    {
        return [
            'November work, January forecast' => ['2026-11-01', '2027-01-08'],
            'December work, February forecast' => ['2026-12-01', '2027-02-08'],
            'Late historical payment' => ['2025-12-01', '2026-10-30'],
        ];
    }

    public function testSameYearDoesNotImposeNewTaxRates(): void
    {
        new TaxYearPublicationGuard()->assertSafeForIndividual(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-10-10'),
        );
        self::assertTrue(true);
    }

    public function testPublisherBlocksCrossYearBeforeCreatingAnyDocument(): void
    {
        $previous = getenv('COOPERATIVE_PROFILE');
        putenv('COOPERATIVE_PROFILE=librecode');
        try {
            self::bootKernel();
            $dates = static::getContainer()->get(Dates::class);
            $dates->setEvaluationTime(new DateTime('2026-12-01'));
            $dates->setInicio(new DateTime('2026-11-01'));
            $dates->setDiaUtilPagamento(5);
            self::assertSame('2027', $dates->getDataPagamento()->format('Y'));

            $member = new Cooperado(
                entityManager: $this->createStub(EntityManagerInterface::class),
                dates: $dates,
                numberFormatter: new NumberFormatter('pt_BR', NumberFormatter::CURRENCY),
                documents: $this->createStub(Documents::class),
                request: $this->createStub(Request::class),
                documentConfiguration: new DocumentConfiguration(),
                anoFiscal: 2026,
                mes: 11,
            );
            $member->setTaxNumber('12345678909');

            $publisher = static::getContainer()->get(LibreCodeAkauntingPublisher::class);
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Publicação fiscal bloqueada');
            // No contacts or accounting writes should be attempted.
            $publisher->publish(['synthetic-individual' => $member], []);
        } finally {
            putenv($previous === false ? 'COOPERATIVE_PROFILE' : 'COOPERATIVE_PROFILE=' . $previous);
        }
    }
}
