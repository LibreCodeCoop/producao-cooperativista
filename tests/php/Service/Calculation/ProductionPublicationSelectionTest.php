<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Akaunting\Document\ProducaoCooperativista;
use App\Service\Akaunting\Document\Values;
use App\Service\Calculation\ProductionPublicationSelection;
use App\Service\Cooperado;
use Doctrine\DBAL\Connection;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductionPublicationSelectionTest extends TestCase
{
    private function members(): array
    {
        return [
            '11111111111' => $this->member('11111111111', 100.325, 73.25, 2),
            '22222222222' => $this->member('22222222222', 50.1, 34.12, 1),
        ];
    }

    private function member(string $tax, float $base, float $net, int $weight): Cooperado
    {
        $member = new ReflectionClass(Cooperado::class)->newInstanceWithoutConstructor();
        $member->setTaxNumber($tax);
        $member->setAkauntingContactId(19);
        $member->setWeight((float) $weight);
        $member->setDependentes(0);

        $values = new Values(2026, 8, $member);
        $values->setBaseProducao($base, false);
        $values->setBruto($net);
        $values->setLiquido($net);
        $values->setLiquidDiscount(0.0);
        $values->setDocumentNumber('PDC_' . $tax);
        $values->setUpdated();

        $doc = new ReflectionClass(ProducaoCooperativista::class)->newInstanceWithoutConstructor();
        $doc->setValues($values);
        $member->setProducaoCooperativista($doc);
        return $member;
    }

    private function selection(array $rows = [
        ['id' => 5, 'tax_number' => '11111111111'],
        ['id' => 8, 'tax_number' => '22222222222'],
    ]): ProductionPublicationSelection
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn($rows);
        return new ProductionPublicationSelection($db);
    }

    public function testDefaultIncludesEveryoneAndExceptionKeepsOtherValuesUnchanged(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $snapshot = $service->snapshot($members, '2026-08');
        self::assertSame(['11111111111' => 5, '22222222222' => 8], $snapshot['ids']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['revision']);
        self::assertSame(array_keys($members), array_keys(
            $service->included($members, '2026-08', $snapshot['revision'], [])
        ));
        $partial = $service->included($members, '2026-08', $snapshot['revision'], [8]);
        self::assertSame([11111111111], array_keys($partial));
        self::assertSame(73.25, $partial['11111111111']->getProducaoCooperativista()->getValues()->getLiquido());
        self::assertSame(34.12, $members['22222222222']->getProducaoCooperativista()->getValues()->getLiquido());
    }

    public function testRecomputedValuesInvalidateOldFinancialReview(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $old = $service->snapshot($members, '2026-08')['revision'];
        $members['11111111111']->setWeight(8.0);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('apuração mudou');
        $service->included($members, '2026-08', $old, []);
    }

    public function testDifferentCompetenceCannotReuseTheReview(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $old = $service->snapshot($members, '2026-08')['revision'];
        $this->expectException(DomainException::class);
        $service->included($members, '2026-09', $old, []);
    }

    public function testUnknownOmissionNeverPublishesAnything(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $revision = $service->snapshot($members, '2026-08')['revision'];
        $this->expectException(DomainException::class);
        $service->included($members, '2026-08', $revision, [999]);
    }

    public function testDuplicateOmissionCannotBeAccepted(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $revision = $service->snapshot($members, '2026-08')['revision'];
        $this->expectException(DomainException::class);
        $service->included($members, '2026-08', $revision, [8, 8]);
    }

    public function testAllOmissionsCannotPublishOnlyTaxes(): void
    {
        $members = $this->members();
        $service = $this->selection();
        $revision = $service->snapshot($members, '2026-08')['revision'];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Todos os cooperados');
        $service->included($members, '2026-08', $revision, [5, 8]);
    }

    public function testMissingOrAmbiguousStagingIdentityFailsClosed(): void
    {
        $members = $this->members();
        $this->expectException(DomainException::class);
        $this->selection([
            ['id' => 5, 'tax_number' => '11111111111'],
            ['id' => 9, 'tax_number' => '11111111111'],
        ])->snapshot($members, '2026-08');
    }

    public function testEmptyMonthCannotPublishTaxesWithoutMembers(): void
    {
        $this->expectException(DomainException::class);
        $this->selection()->included([], '2026-08', str_repeat('a', 64), []);
    }
}
