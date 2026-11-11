<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\IncomeAppropriationLedger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DomainException;
use PHPUnit\Framework\TestCase;

final class IncomeAppropriationLedgerTest extends TestCase
{
    private Connection $db;
    private IncomeAppropriationLedger $journal;

    protected function setUp(): void
    {
        putenv('AKAUNTING_COMPANY_ID=1');
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(
            'CREATE TABLE production_income_appropriations ('
            . 'company_id INTEGER, source_type TEXT, source_id INTEGER, competence TEXT, '
            . 'amount_cents INTEGER, source_total_cents INTEGER, revision TEXT, '
            . 'state TEXT, created_at TEXT, confirmed_at TEXT, '
            . 'PRIMARY KEY(company_id, source_type, source_id, competence))'
        );
        $this->journal = new IncomeAppropriationLedger($this->db);
    }

    private function invoice(float $amount, float $total = 1000.0): array
    {
        return [
            'table' => 'invoice_forecast',
            'id' => 2365,
            'type' => 'invoice',
            'category_type' => 'income',
            'amount' => $amount,
            'bruto' => $amount,
            'metadata' => json_encode(['amount' => $total], JSON_THROW_ON_ERROR),
        ];
    }

    private function income(float $amount): array
    {
        return [
            'table' => 'transaction',
            'id' => 4362,
            'type' => 'income',
            'category_type' => 'income',
            'amount' => $amount,
            'bruto' => $amount,
            'general_income' => true,
            'metadata' => '{}',
        ];
    }

    public function testUnpaidInvoiceCannotBeAllocatedAgainAfterLaterPayment(): void
    {
        $row = $this->invoice(1000);
        $revision = str_repeat('a', 64);
        $this->journal->reserve([$row], '2026-09', $revision);
        $this->journal->confirm('2026-09', $revision);

        $later = $this->invoice(1000);
        $later['table'] = 'invoice_transaction';
        self::assertNull($this->journal->remaining($later));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM production_income_appropriations WHERE state = 'confirmed'"
        ));
    }

    public function testGeneralCashIncomeNeverReturnsToNextMonth(): void
    {
        $row = $this->income(540.07);
        $revision = str_repeat('b', 64);
        $this->journal->reserve([$row], '2026-09', $revision);
        $this->journal->confirm('2026-09', $revision);
        self::assertNull($this->journal->remaining($row));
    }

    public function testPartiallyAllocatedInvoiceCanDistributeOnlyRemainingBalance(): void
    {
        $row = $this->invoice(400);
        $revision = str_repeat('c', 64);
        $this->journal->reserve([$row], '2026-09', $revision);
        $this->journal->confirm('2026-09', $revision);
        $later = $this->invoice(800);
        $later['table'] = 'invoice_transaction';
        $next = $this->journal->remaining($later);
        self::assertNotNull($next);
        self::assertSame(600.0, $next['amount']);
        self::assertSame(600.0, $next['bruto']);
        $this->journal->reserve([$next], '2026-10', str_repeat('d', 64));
        $this->journal->confirm('2026-10', str_repeat('d', 64));
        self::assertNull($this->journal->remaining($later));
    }

    public function testMixedPaidAndApprovedUnpaidPartsRespectTotalAfterPriorAllocation(): void
    {
        $revision = str_repeat('a', 64);
        $this->journal->reserve([$this->invoice(400)], '2026-08', $revision);
        $this->journal->confirm('2026-08', $revision);
        $payment = $this->invoice(600);
        $payment['table'] = 'invoice_transaction';
        $cash = $this->journal->remaining($payment);
        self::assertSame(600.0, $cash['amount']);
        $unpaid = $this->invoice(400);
        self::assertNull($this->journal->remaining($unpaid, 60000));
    }

    public function testUnknownExternalWriteOutcomeBlocksRetry(): void
    {
        $revision = str_repeat('e', 64);
        $this->journal->reserve([$this->invoice(1000)], '2026-09', $revision);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sem resultado financeiro confirmado');
        $this->journal->remaining($this->invoice(1000));
    }

    public function testDuplicateReservationForSameSourceIsRejectedAndPreserved(): void
    {
        $revision = str_repeat('f', 64);
        $this->journal->reserve([$this->income(540.07)], '2026-09', $revision);
        try {
            $this->journal->reserve([$this->income(540.07)], '2026-09', $revision);
            self::fail('Expected duplicate reservation to be blocked');
        } catch (DomainException $error) {
            self::assertStringContainsString('já apropriada', $error->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM production_income_appropriations'
        ));
    }

    public function testNonIncomeMovementIsUnaffected(): void
    {
        $cost = $this->income(100);
        $cost['type'] = 'expense';
        self::assertSame($cost, $this->journal->remaining($cost));
    }
}
