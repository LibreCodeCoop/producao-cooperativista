<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Domain\Competencia;
use App\Service\Akaunting\Source\Categories;
use App\Service\Calculation\ImportedSourceReview;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ImportedSourceReviewTest extends TestCase
{
    public function testFinancialDeficitAllowsSourceReviewButNeverPublication(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('CREATE TABLE timesheet (id INT, user_id INT, begin TEXT, duration INT)');
        $database->executeStatement('CREATE TABLE invoices (id INT, document_number TEXT, amount REAL, due_at TEXT, category_id INT, metadata TEXT, transaction_of_month TEXT, type TEXT, category_type TEXT, archive INT)');
        $database->executeStatement('CREATE TABLE transactions (id INT, type TEXT, category_id INT, category_name TEXT, customer_reference TEXT, amount REAL, metadata TEXT, transaction_of_month TEXT, archive INT)');
        $database->insert('timesheet', ['id' => 1, 'user_id' => 5, 'begin' => '2026-09-04', 'duration' => 3600]);
        $database->insert('timesheet', ['id' => 2, 'user_id' => 19, 'begin' => '2026-09-05', 'duration' => 7200]);
        $database->insert('timesheet', ['id' => 3, 'user_id' => 30, 'begin' => '2026-08-03', 'duration' => 5000]);

        foreach ([
            [2329, 'sent', 29877.75],
            [2363, 'draft', 29877.75],
            [2364, 'paid', 100.00],
        ] as [$id, $status, $amount]) {
            $database->insert('invoices', [
                'id' => $id, 'document_number' => (string) $id, 'amount' => $amount,
                'due_at' => '2026-10-20', 'category_id' => 101,
                'metadata' => json_encode(['status' => $status], JSON_THROW_ON_ERROR),
                'transaction_of_month' => '2026-10', 'type' => 'invoice', 'category_type' => 'income', 'archive' => 0,
            ]);
        }
        foreach ([
            [1, 'income', 101, 100.00, '[]', 'Entradas', ''],
            [2, 'income-transfer', 1, 30000.00, '[]', 'Transferência', ''],
            [3, 'expense', 201, 2007.63, '[]', 'Cliente', '1234'],
            [4, 'expense', 301, 500.00, '[]', 'Infraestrutura', ''],
        ] as [$id, $type, $category, $amount, $metadata, $categoryName, $reference]) {
            $database->insert('transactions', [
                'id' => $id, 'type' => $type, 'category_id' => $category,
                'category_name' => $categoryName, 'customer_reference' => $reference,
                'amount' => $amount, 'metadata' => $metadata,
                'transaction_of_month' => '2026-10', 'archive' => 0,
            ]);
        }

        $old = [];
        foreach ([
            'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID' => '100',
            'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID' => '200',
            'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID' => '300',
        ] as $key => $value) {
            $old[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $categories = $this->createStub(Categories::class);
            $categories->method('getChildrensCategories')->willReturnCallback(
                static fn (int $parent): array => [$parent + 1],
            );
            $review = new ImportedSourceReview($database, $categories);
            $result = $review->read(Competencia::fromString('2026-09'));
            self::assertSame('2026-09', $result['competence']);
            self::assertSame('2026-10', $result['financial_month']);
            self::assertFalse($result['publication_allowed']);
            self::assertArrayNotHasKey('revision', $result);
            self::assertSame(['entries' => 2, 'members' => 2, 'seconds' => 10800], $result['hours']);
            self::assertSame(['count' => 1, 'amount' => 100.0], $result['income_transactions']);
            self::assertSame(2007.63, $result['client_cost_transactions']['amount']);
            self::assertSame([['label' => '1234', 'amount' => 2007.63]], $result['client_cost_transactions']['by_customer']);
            self::assertSame(500.0, $result['internal_expense_transactions']['amount']);
            self::assertSame(2, $result['unconfirmed_invoices']['count']);
            self::assertSame(59755.5, $result['unconfirmed_invoices']['amount']);
            self::assertSame([2329, 2363], array_column($result['unconfirmed_invoices']['documents'], 'id'));

            $following = $review->read(Competencia::fromString('2026-10'));
            self::assertSame('2026-11', $following['financial_month']);
            self::assertSame(0, $following['hours']['entries']);
            self::assertSame(0.0, $following['unconfirmed_invoices']['amount']);
        } finally {
            foreach ($old as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            $database->close();
        }
    }
}
