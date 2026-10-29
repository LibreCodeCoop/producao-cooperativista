<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Akaunting\CapitalSocialSettings;
use App\Service\Akaunting\Source\CapitalSocial;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Only runs inside the guarded, isolated *_test Akaunting database. */
final class ProducaoCooperativistaCapitalSocialTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        putenv('AKAUNTING_COMPANY_ID=1');
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine.dbal.akaunting_connection');
        foreach (['document_items', 'documents', 'transactions', 'contacts'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $this->connection->executeStatement('CREATE TABLE contacts (id INT PRIMARY KEY, company_id INT NOT NULL, name VARCHAR(191) NOT NULL, tax_number VARCHAR(20) NULL)');
        $this->connection->executeStatement('CREATE TABLE transactions (id INT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, category_id INT NOT NULL, amount DECIMAL(15,2) NOT NULL, currency_code VARCHAR(5) NOT NULL, description VARCHAR(191) NOT NULL DEFAULT \'\', paid_at DATETIME NOT NULL, type VARCHAR(30) NOT NULL, document_id INT NULL, deleted_at DATETIME NULL)');
        $this->connection->executeStatement('CREATE TABLE documents (id INT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, contact_name VARCHAR(191) NOT NULL, type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, currency_code VARCHAR(5) NOT NULL, category_id INT NOT NULL, amount DECIMAL(15,2) NOT NULL, due_at DATETIME NOT NULL, deleted_at DATETIME NULL)');
        $this->connection->executeStatement('CREATE TABLE document_items (id INT PRIMARY KEY, document_id INT NOT NULL, item_id INT NOT NULL, total DECIMAL(15,2) NOT NULL, deleted_at DATETIME NULL)');
        foreach ([1,2] as $person) {
            $this->connection->insert('contacts', ['id' => $person, 'company_id' => 1, 'name' => 'Cooperado ' . $person, 'tax_number' => null]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['document_items', 'documents', 'transactions', 'contacts'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        putenv('AKAUNTING_COMPANY_ID');
        parent::tearDown();
    }

    private function transaction(int $id, int $contact, string $type, int $category, string $amount, ?int $document = null, string $date = '2025-05-03 12:00:00'): void
    {
        $this->connection->insert('transactions', [
            'id' => $id, 'company_id' => 1, 'contact_id' => $contact, 'category_id' => $category,
            'amount' => $amount, 'currency_code' => 'BRL', 'paid_at' => $date, 'type' => $type, 'document_id' => $document,
            'deleted_at' => null,
        ]);
    }

    private function document(int $id, int $contact, string $type, int $category, string $amount, array $items = [], string $status = 'paid'): void
    {
        $this->connection->insert('documents', [
            'id' => $id, 'company_id' => 1, 'contact_id' => $contact, 'contact_name' => 'Cooperado ' . $contact,
            'category_id' => $category, 'type' => $type, 'status' => $status, 'amount' => $amount, 'currency_code' => 'BRL',
            'due_at' => '2025-05-01 12:00:00', 'deleted_at' => null,
        ]);
        foreach ($items as $itemId => $value) {
            $this->connection->insert('document_items', [
                'id' => $id * 10 + $itemId, 'document_id' => $id, 'item_id' => $itemId,
                'total' => $value, 'deleted_at' => null,
            ]);
        }
    }

    public function testConfirmedMovementsIncludePaymentsRetentionAndRefundExactlyOnce(): void
    {
        $this->transaction(1, 1, 'income', 25, '100.00');
        $this->document(10, 1, 'invoice', 25, '50.00', [11 => '50.00']);
        $this->transaction(10, 1, 'income', 25, '50.00', 10);
        $this->document(11, 1, 'invoice', 2, '25.00', [11 => '25.00']);
        $this->transaction(11, 1, 'income', 2, '25.00', 11);
        $this->document(12, 1, 'bill', 16, '300.00', [11 => '300.00']);
        $this->transaction(12, 1, 'expense', 16, '300.00', 12);
        $this->document(13, 1, 'bill', 11, '90.00', [11 => '-10.00',12 => '100.00']);
        $this->transaction(13, 1, 'expense', 11, '90.00', 13);
        $this->document(14, 2, 'invoice', 25, '40.00', [11 => '40.00'], 'draft');
        $rows = new CapitalSocial($this->connection, new CapitalSocialSettings(25, 11))->rows();
        self::assertCount(6, $rows);
        $confirmed = array_filter($rows, static fn (array $r): bool => $r['status'] === 'confirmado');
        self::assertCount(5, $confirmed);
        self::assertEqualsWithDelta(-115.0, array_sum(array_column($confirmed, 'amount')), 0.001);
        self::assertSame(['integralizacao','devolucao','retencao'], array_values(array_unique(array_column($confirmed, 'kind'))));
        self::assertSame('revisar', array_values(array_filter($rows, static fn (array $r): bool => $r['contact_id'] === 2))[0]['status']);
    }

    public function testPartialAndMixedOrUnpaidDocumentsAreNotAccidentallyConfirmed(): void
    {
        $this->document(11, 1, 'invoice', 25, '50.00', [11 => '50.00']);
        $this->transaction(10, 1, 'income', 25, '20.00', 11);
        $this->document(12, 1, 'bill', 16, '100.00', [11 => '20.00',12 => '80.00']);
        $this->transaction(11, 1, 'expense', 16, '100.00', 12);
        $this->document(13, 1, 'invoice', 2, '30.00', [11 => '30.00'], 'draft');
        $rows = (new CapitalSocial($this->connection, new CapitalSocialSettings(25, 11)))->rows();
        self::assertCount(3, $rows);
        self::assertSame(['revisar'], array_values(array_unique(array_column($rows, 'status'))));
        self::assertEqualsWithDelta(0, array_sum(array_column($rows, 'amount')), 0.001);
    }

    public function testCutoffAndMemberSelectionApplyToBankPayments(): void
    {
        $this->transaction(1, 1, 'income', 25, '50.00', null, '2025-05-01 08:00:00');
        $this->transaction(2, 1, 'income', 25, '50.00', null, '2025-06-01 08:00:00');
        $this->transaction(3, 2, 'income', 25, '100.00', null, '2025-05-01 08:00:00');
        $rows = (new CapitalSocial($this->connection, new CapitalSocialSettings(25, 11)))->rows(1, '2025-05-31');
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['contact_id']);
        self::assertEqualsWithDelta(50, $rows[0]['amount'], 0.001);
    }

    public function testInvalidCutoffAndIdentifiersAreRejected(): void
    {
        $service = new CapitalSocial($this->connection, new CapitalSocialSettings(25, 11));
        $this->expectException(\InvalidArgumentException::class);
        $service->rows(null, '2025-02-31');
    }

    public function testOppositeDirectionTransactionCannotConfirmCapitalInvoice(): void
    {
        $this->document(16, 1, 'invoice', 25, '75.00', [11 => '75.00']);
        $this->transaction(16, 1, 'expense', 25, '75.00', 16);
        $rows = (new CapitalSocial($this->connection, new CapitalSocialSettings(25, 11)))->rows();
        self::assertCount(1, $rows);
        self::assertSame('revisar', $rows[0]['status']);
        self::assertEqualsWithDelta(0, $rows[0]['amount'], 0.001);
    }

    public function testPaymentCurrencyMismatchRequiresReview(): void
    {
        $this->document(16, 1, 'invoice', 25, '75.00', [11 => '75.00']);
        $this->transaction(16, 1, 'income', 25, '75.00', 16);
        $this->connection->update('transactions', ['currency_code' => 'USD'], ['id' => 16]);
        $rows = (new CapitalSocial($this->connection,new CapitalSocialSettings(25,11)))->rows();
        self::assertSame('revisar',$rows[0]['status']);
        self::assertEqualsWithDelta(0,$rows[0]['amount'],0.001);
    }

    public function testEmptyLedgerDoesNotProduceError500(): void
    {
        self::assertSame([],(new CapitalSocial($this->connection,new CapitalSocialSettings(25,11)))->rows());
    }
}
