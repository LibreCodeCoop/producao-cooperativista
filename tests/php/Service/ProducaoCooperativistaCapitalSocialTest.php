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

/**
 * Financial input contract against an isolated Akaunting-shaped SQL schema.
 * No mocks of internal methods, no production database/API calls.
 */
final class ProducaoCooperativistaCapitalSocialTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine.dbal.akaunting_connection');
        foreach (['document_items', 'documents', 'transactions', 'contacts'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $this->connection->executeStatement(
            'CREATE TABLE contacts (id INT PRIMARY KEY, name VARCHAR(191) NOT NULL)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE transactions (id INT PRIMARY KEY, contact_id INT NOT NULL, category_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, paid_at DATETIME NOT NULL, document_id INT NULL, deleted_at DATETIME NULL)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE documents (id INT PRIMARY KEY, contact_id INT NOT NULL, contact_name VARCHAR(191) NOT NULL, category_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, due_at DATETIME NOT NULL, deleted_at DATETIME NULL)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE document_items (id INT PRIMARY KEY, document_id INT NOT NULL, item_id INT NOT NULL, price DECIMAL(12,2) NOT NULL, deleted_at DATETIME NULL)'
        );
    }

    protected function tearDown(): void
    {
        foreach (['document_items', 'documents', 'transactions', 'contacts'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        parent::tearDown();
    }

    public function testCapitalContributionsAreReadFromThreeSourcesWithoutTurningIntoIncome(): void
    {
        $db = $this->connection;
        $db->insert('contacts', ['id' => 1, 'name' => 'Cooperado A']);
        $db->insert('transactions', [
            'id' => 1, 'contact_id' => 1, 'category_id' => 999,
            'amount' => 100, 'paid_at' => '2025-03-01 00:00:00',
            'document_id' => null, 'deleted_at' => null,
        ]);
        $db->insert('transactions', [
            'id' => 2, 'contact_id' => 1, 'category_id' => 10,
            'amount' => 200, 'paid_at' => '2025-03-01 00:00:00',
            'document_id' => null, 'deleted_at' => null,
        ]);
        $db->insert('transactions', [
            'id' => 3, 'contact_id' => 1, 'category_id' => 999,
            'amount' => 777, 'paid_at' => '2025-03-01 00:00:00',
            'document_id' => null, 'deleted_at' => '2025-03-02 00:00:00',
        ]);
        $db->insert('documents', [
            'id' => 4, 'contact_id' => 1, 'contact_name' => 'Cooperado A',
            'category_id' => 999, 'amount' => 50, 'due_at' => '2025-04-01 00:00:00',
            'deleted_at' => null,
        ]);
        $db->insert('documents', [
            'id' => 5, 'contact_id' => 1, 'contact_name' => 'Cooperado A',
            'category_id' => 10, 'amount' => 90, 'due_at' => '2025-04-01 00:00:00',
            'deleted_at' => null,
        ]);
        $db->insert('document_items', [
            'id' => 1, 'document_id' => 5, 'item_id' => 888,
            'price' => 75, 'deleted_at' => null,
        ]);
        $db->insert('document_items', [
            'id' => 2, 'document_id' => 5, 'item_id' => 777,
            'price' => 123, 'deleted_at' => null,
        ]);

        $rows = new CapitalSocial($db, new CapitalSocialSettings(999, 888))->rows();
        self::assertCount(3, $rows);
        self::assertSame(
            ['transaction', 'documents', 'documents_item'],
            array_column($rows, 'table')
        );
        self::assertEqualsWithDelta(225, array_sum(array_map(floatval(...), array_column($rows, 'amount'))), 0.001);
        self::assertSame(['Cooperado A'], array_values(array_unique(array_column($rows, 'name'))));
    }

    public function testMissingMappingDoesNotInventCapitalContributions(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sem capital social');
        new CapitalSocial($this->connection, new CapitalSocialSettings(2, 2))->rows();
    }

    public function testInvalidSettingsDoNotSilentlyDefaultToAnotherClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CapitalSocialSettings(0, 11);
    }
}
