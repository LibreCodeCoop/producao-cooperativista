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
        $database->executeStatement('CREATE TABLE timesheet (id INT, user_id INT, project_id INT, begin TEXT, duration INT)');
        $database->executeStatement('CREATE TABLE users (id INT, alias TEXT, peso REAL)');
        $database->executeStatement('CREATE TABLE projects (id INT, customer_id INT)');
        $database->executeStatement('CREATE TABLE customers (id INT, name TEXT)');
        $database->insert('customers', ['id' => 17, 'name' => 'Cliente A']);
        $database->insert('customers', ['id' => 18, 'name' => 'Cliente B']);
        $database->insert('projects', ['id' => 70, 'customer_id' => 17]);
        $database->insert('projects', ['id' => 80, 'customer_id' => 18]);
        $database->insert('users', ['id' => 5, 'alias' => 'Cooperada A', 'peso' => 1.5]);
        $database->insert('users', ['id' => 19, 'alias' => 'Cooperado B', 'peso' => 2.0]);
        $database->executeStatement('CREATE TABLE invoices (id INT, document_number TEXT, contact_name TEXT, amount REAL, due_at TEXT, category_id INT, metadata TEXT, transaction_of_month TEXT, type TEXT, category_type TEXT, archive INT)');
        $database->executeStatement('CREATE TABLE transactions (id INT, type TEXT, category_id INT, category_name TEXT, customer_reference TEXT, amount REAL, metadata TEXT, transaction_of_month TEXT, archive INT)');
        $database->insert('timesheet', ['id' => 1, 'user_id' => 5, 'project_id' => 70, 'begin' => '2026-09-04', 'duration' => 3600]);
        $database->insert('timesheet', ['id' => 2, 'user_id' => 19, 'project_id' => 70, 'begin' => '2026-09-05', 'duration' => 7200]);
        $database->insert('timesheet', ['id' => 3, 'user_id' => 30, 'project_id' => 80, 'begin' => '2026-08-03', 'duration' => 5000]);

        foreach ([
            [2329, 'sent', 29877.75],
            [2363, 'draft', 29877.75],
            [2364, 'paid', 100.00],
        ] as [$id, $status, $amount]) {
            $database->insert('invoices', [
                'id' => $id, 'document_number' => (string) $id, 'contact_name' => 'Cliente Teste', 'amount' => $amount,
                'due_at' => '2026-10-20', 'category_id' => 101,
                'metadata' => json_encode(['status' => $status], JSON_THROW_ON_ERROR),
                'transaction_of_month' => '2026-10', 'type' => 'invoice', 'category_type' => 'income', 'archive' => 0,
            ]);
        }
        foreach ([
            [1, 'income', 101, 100.00, '{"document_id":2364}', 'Entradas', ''],
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
            self::assertSame(['entries' => 2, 'members' => 2, 'seconds' => 10800, 'by_member' => [
                ['id' => 5, 'name' => 'Cooperada A', 'weight' => 1.5, 'entries' => 1, 'seconds' => 3600],
                ['id' => 19, 'name' => 'Cooperado B', 'weight' => 2.0, 'entries' => 1, 'seconds' => 7200],
            ]], $result['hours']);
            self::assertCount(2, $result['data']);
            self::assertSame(5, $result['data'][0]['kimai_user_id']);
            self::assertSame(1.5, $result['data'][0]['peso']);
            self::assertSame(3600, $result['data'][0]['hours_seconds']);
            self::assertNull($result['data'][0]['base_producao']);
            self::assertNull($result['data'][0]['bruto']);
            self::assertNull($result['data'][0]['liquido']);
            self::assertSame([[
                'nome' => 'Cliente A', 'trabalhado' => '1:00',
                'percentual_trabalhado' => 33.33, 'total_cliente' => 3,
            ]], json_decode($result['data'][0]['trabalhado'], true));
            self::assertSame([[
                'nome' => 'Cliente A', 'trabalhado' => '2:00',
                'percentual_trabalhado' => 66.67, 'total_cliente' => 3,
            ]], json_decode($result['data'][1]['trabalhado'], true));
            self::assertSame(['count' => 1, 'amount' => 100.0], $result['income_transactions']);
            self::assertSame(2007.63, $result['client_cost_transactions']['amount']);
            self::assertSame([['label' => '1234', 'amount' => 2007.63]], $result['client_cost_transactions']['by_customer']);
            self::assertSame(500.0, $result['internal_expense_transactions']['amount']);
            self::assertSame(2, $result['unconfirmed_invoices']['count']);
            self::assertSame(59755.5, $result['unconfirmed_invoices']['amount']);
            self::assertSame([2329, 2363], array_column($result['unconfirmed_invoices']['documents'], 'id'));
            self::assertSame(['sent', 'draft'], array_column($result['unconfirmed_invoices']['documents'], 'status'));
            self::assertSame(['Cliente Teste', 'Cliente Teste'], array_column($result['unconfirmed_invoices']['documents'], 'customer'));
            $pending = $review->pendingInvoiceReport(Competencia::fromString('2026-09'));
            self::assertSame(2, $pending['metadata']['total']);
            self::assertSame('2026-10', $pending['metadata']['financial_month']);
            self::assertFalse($pending['metadata']['truncated']);
            self::assertCount(2, $pending['data']);
            self::assertSame('Cliente Teste', $pending['data'][0]['cliente']);
            self::assertSame('sent', $pending['data'][0]['status_documento']);
            self::assertSame('draft', $pending['data'][1]['status_documento']);
            self::assertSame('Ainda não aplicável (rascunho)', $pending['data'][1]['recebimento']);
            self::assertSame(
                'Conferir se a fatura está pronta para emitir e enviar ao cliente.',
                $pending['data'][1]['o_que_conferir'],
            );
            self::assertStringContainsString('registrar ou vincular', $pending['data'][0]['o_que_conferir']);

            // A receipt associated with a sent invoice cannot remove it from
            // reconciliation when the Akaunting invoice still says 'sent'.
            $database->insert('invoices', [
                'id' => 2365, 'document_number' => '2365', 'contact_name' => 'Cliente Teste',
                'amount' => 120.0, 'due_at' => '2026-10-20', 'category_id' => 101,
                'metadata' => '{"status":"sent"}', 'transaction_of_month' => '2026-10',
                'type' => 'invoice', 'category_type' => 'income', 'archive' => 0,
            ]);
            $database->insert('transactions', [
                'id' => 5, 'type' => 'income', 'category_id' => 101, 'category_name' => 'Entradas',
                'customer_reference' => '', 'amount' => 35.0,
                'metadata' => '{"document_id":2365}', 'transaction_of_month' => '2026-10',
                'archive' => 0,
            ]);
            // An unrelated expense must not be mistaken for income received.
            $database->insert('transactions', [
                'id' => 6, 'type' => 'expense', 'category_id' => 201, 'category_name' => 'Cliente',
                'customer_reference' => '', 'amount' => 75.0,
                'metadata' => '{"document_id":2363}', 'transaction_of_month' => '2026-10',
                'archive' => 0,
            ]);
            // Paid in Akaunting but without an imported customer receipt:
            // retain it as a reconciliation inconsistency rather than hiding it.
            $database->insert('invoices', [
                'id' => 2366, 'document_number' => '2366', 'contact_name' => 'Cliente Teste',
                'amount' => 90.0, 'due_at' => '2026-10-22', 'category_id' => 101,
                'metadata' => '{"status":"paid"}', 'transaction_of_month' => '2026-10',
                'type' => 'invoice', 'category_type' => 'income', 'archive' => 0,
            ]);
            $recheck = $review->pendingInvoiceReport(Competencia::fromString('2026-09'));
            self::assertSame(4, $recheck['metadata']['total']);
            self::assertSame('Ainda não aplicável (rascunho)', $recheck['data'][1]['recebimento']);
            self::assertSame('R$ 35,00 registrado no período', $recheck['data'][2]['recebimento']);
            self::assertStringContainsString('atualizar a situação', $recheck['data'][2]['o_que_conferir']);
            self::assertSame('paid', $recheck['data'][3]['status_documento']);
            self::assertStringContainsString('Conferir o registro do pagamento', $recheck['data'][3]['o_que_conferir']);

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
