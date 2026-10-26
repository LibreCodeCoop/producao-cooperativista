<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 *
 * @author LibreCode contributors
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Producao\Category;
use App\Entity\Producao\Invoice;
use App\Entity\Producao\Transaction;
use App\Helper\Dates;
use App\Service\Movimentacao;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Tests\Php\TestCase;

final class MovimentacaoTest extends TestCase
{
    private const LEGACY_COOPERADO_CATEGORY_ID = 9102;
    private const CLIENT_EXPENSE_CATEGORY_ID = 9201;

    private EntityManagerInterface $entityManager;
    private Dates $dates;
    private Movimentacao $movimentacao;

    protected function setUp(): void
    {
        static::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->dates = $container->get(Dates::class);
        $this->movimentacao = $container->get(Movimentacao::class);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM transactions');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM invoices');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM categories');
        $this->entityManager->clear();

        $this->dates->setInicio(new DateTime('2026-04-01 00:00:00'));
    }

    public function testGetMovimentacaoFinanceiraIgnoresMissingCustomerReferenceOutsideClientHierarchies(): void
    {
        $this->persistClientHierarchyRoots();
        $this->persistCategory(
            id: self::LEGACY_COOPERADO_CATEGORY_ID,
            name: 'Cooperado > Produção > Plano de saúde',
            parentId: 11,
        );
        $this->persistBill(
            id: 1005,
            categoryId: self::LEGACY_COOPERADO_CATEGORY_ID,
            categoryName: 'Cooperado > Produção > Plano de saúde',
            customerReference: null,
            contactName: 'Fornecedor Legado',
        );

        $movimentacao = $this->movimentacao->getMovimentacaoFinanceira();

        self::assertCount(1, $movimentacao);
        self::assertArrayHasKey(1005, $movimentacao);
        self::assertNull($movimentacao[1005]['customer_reference']);
    }

    public function testGetMovimentacaoFinanceiraRejectsMissingCustomerReferenceForClientExpenseCategory(): void
    {
        $this->persistClientHierarchyRoots();
        $this->persistCategory(
            id: self::CLIENT_EXPENSE_CATEGORY_ID,
            name: 'Cliente > Externo > Dispêndio',
            parentId: (int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'),
        );
        $this->persistBill(
            id: 1006,
            categoryId: self::CLIENT_EXPENSE_CATEGORY_ID,
            categoryName: 'Cliente > Externo > Dispêndio',
            customerReference: null,
            contactName: 'Fornecedor Cliente',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Código de cliente inválido na movimentação do Akaunting');

        $this->movimentacao->getMovimentacaoFinanceira();
    }

    public function testCustomerCostsRespectFilterEvenAfterUnfilteredAccess(): void
    {
        $this->persistClientHierarchyRoots();
        $this->persistCategory(
            id: self::CLIENT_EXPENSE_CATEGORY_ID,
            name: 'Cliente > Externo > Dispêndio',
            parentId: (int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'),
        );

        foreach ([
            ['id' => 201, 'amount' => 100.0, 'fixed' => true],
            ['id' => 202, 'amount' => 70.0, 'fixed' => false],
            ['id' => 203, 'amount' => 30.0, 'fixed' => false],
        ] as $entry) {
            $this->movimentacao->setMovimentacao([
                'id' => $entry['id'],
                'category_id' => self::CLIENT_EXPENSE_CATEGORY_ID,
                'category_type' => 'expense',
                'customer_reference' => '12345678000190',
                'percentual_desconto_fixo' => $entry['fixed'],
                'amount' => $entry['amount'],
            ]);
        }

        self::assertEqualsWithDelta(
            200,
            $this->movimentacao->getCustosPorCliente()['12345678000190'],
            0.001
        );
        self::assertEqualsWithDelta(
            100,
            $this->movimentacao->getCustosPorCliente(percentualDescontoFixo: true)['12345678000190'],
            0.001
        );
        self::assertEqualsWithDelta(
            100,
            $this->movimentacao->getCustosPorCliente(percentualDescontoFixo: false)['12345678000190'],
            0.001
        );

        // Existing monthly surplus calculation must deduct every attributable
        // customer cost, including those linked to fixed-rate invoices.
        self::assertEqualsWithDelta(200, $this->movimentacao->getTotalDispendiosClientesPercentualMovel(), 0.001);
    }

    public function testFixedRateSurplusProratesInvoiceTaxesAcrossPartialReceipts(): void
    {
        $this->persistCategory(id: 45, name: 'Cliente > Receita', parentId: null, type: 'income');
        $this->persistCategory(id: 20, name: 'Cliente > Serviços', parentId: 45, type: 'income');
        foreach ([
            ['id' => 301, 'net' => 40.0, 'gross' => 44.0],
            ['id' => 302, 'net' => 60.0, 'gross' => 66.0],
        ] as $payment) {
            $this->movimentacao->setMovimentacao([
                'table' => 'invoice_transaction',
                'id' => $payment['id'],
                'category_id' => 20,
                'category_type' => 'income',
                'customer_reference' => '12345678000190',
                'percentual_desconto_fixo' => true,
                'discount_percentage' => 20.0,
                'amount' => $payment['net'],
                'bruto' => $payment['gross'],
                'metadata' => json_encode([
                    'items' => ['data' => [['price' => 110.0]]],
                    'item_taxes' => ['data' => [['amount' => 10.0]]],
                ]),
            ]);
        }

        // Two partial receipts retain 4 + 6 in tax, not 10 + 10.
        self::assertEqualsWithDelta(20.0, $this->movimentacao->getTotalSobrasClientesPercentualFixo(), 0.001);
    }

    public function testFixedRatePartialReceiptWithoutOriginalGrossIsRejected(): void
    {
        $this->persistCategory(id: 45, name: 'Cliente > Receita', parentId: null, type: 'income');
        $this->persistCategory(id: 20, name: 'Cliente > Serviços', parentId: 45, type: 'income');
        $this->movimentacao->setMovimentacao([
            'table' => 'invoice_transaction',
            'id' => 303,
            'category_id' => 20,
            'category_type' => 'income',
            'customer_reference' => '12345678000190',
            'percentual_desconto_fixo' => true,
            'discount_percentage' => 20.0,
            'amount' => 40.0,
            'bruto' => 44.0,
            'metadata' => json_encode(['item_taxes' => ['data' => [['amount' => 10.0]]]]),
        ]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('bruto original ausente');
        $this->movimentacao->getTotalSobrasClientesPercentualFixo();
    }

    public function testNoVariableCapitalReportsActionableDeficitInsteadOfDivisionByZero(): void
    {
        $this->persistCategory(
            id: 16,
            name: 'Cooperativa > Dispêndio',
            parentId: null,
        );
        $this->movimentacao->setMovimentacao([
            'id' => 901,
            'category_id' => 16,
            'category_type' => 'expense',
            'transaction_of_month' => '2026-05',
            'percentual_desconto_fixo' => false,
            'amount' => 200.0,
            'archive' => 0,
            'customer_reference' => null,
        ]);
        $this->movimentacao->setPercentualMaximo(25);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Base administrativa indisponível');
        $this->movimentacao->percentualAdministrativo();
    }

    public function testInvalidAdministrativePercentageCannotBeAppliedSilently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entre 0 e 100');
        $this->movimentacao->setPercentualMaximo(-5);
    }

    public function testIndependentSourceIdsDoNotOverwriteOneAnother(): void
    {
        $this->persistCategory(id: 199, name: 'Despesa geral', parentId: null);
        $this->persistBill(
            id: 1005,
            categoryId: 199,
            categoryName: 'Despesa geral',
            customerReference: null,
            contactName: 'Fornecedor teste',
        );

        $transaction = new Transaction();
        $transaction->fromArray([
            'id' => 1005,
            'type' => 'expense',
            'paid_at' => '2026-05-04 10:00:00',
            'transaction_of_month' => '2026-05',
            'amount' => 80.0,
            'discount_percentage' => null,
            'currency_code' => 'BRL',
            'reference' => 'Movimentação direta',
            'nfse' => null,
            'tax_number' => null,
            'customer_reference' => null,
            'contact_id' => 9,
            'contact_reference' => null,
            'contact_name' => 'Fornecedor direto',
            'contact_type' => 'vendor',
            'category_id' => 199,
            'category_name' => 'Despesa geral',
            'category_type' => 'expense',
            'archive' => 0,
            'metadata' => [],
        ]);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $records = $this->movimentacao->getMovimentacaoFinanceira();
        self::assertCount(2, $records);
        self::assertArrayHasKey(1005, $records);
        self::assertArrayHasKey('invoice:1005', $records);

        $invoice = $records['invoice:1005'];
        $invoice['base_producao'] = 10.0;
        $this->movimentacao->setMovimentacao($invoice);

        self::assertSame(10.0, $this->movimentacao->getMovimentacaoFinanceira()['invoice:1005']['base_producao']);
        self::assertSame('transaction', $this->movimentacao->getMovimentacaoFinanceira()[1005]['table']);
        self::assertEqualsWithDelta(136.78, array_sum(array_column($this->movimentacao->getSaidas(), 'amount')), 0.001);
    }

    public function testAdministrativeRateUsesEligibleCustomerEntriesNotBankBalance(): void
    {
        $this->persistCategory(id: 45, name: 'Cliente > Receita', parentId: null, type: 'income');
        $this->persistCategory(id: 20, name: 'Cliente > Serviços', parentId: 45, type: 'income');
        $this->persistCategory(id: 16, name: 'Cooperativa > Dispêndio', parentId: null);

        // The calculation receives only period-specific financial movements.
        // No banking account balance is available to this service.
        foreach ([
            [
                'id' => 1,
                'amount' => 20000.0,
                'category_id' => 20,
                'category_type' => 'income',
                'transaction_of_month' => '2026-05',
                'archive' => 0,
                'percentual_desconto_fixo' => false,
            ],
            [
                'id' => 2,
                'amount' => 8000.0,
                'category_id' => 16,
                'category_type' => 'expense',
                'transaction_of_month' => '2026-05',
                'archive' => 0,
                'percentual_desconto_fixo' => false,
            ],
        ] as $row) {
            $this->movimentacao->setMovimentacao($row);
        }

        $this->movimentacao->setPercentualMaximo(10);
        $this->assertEqualsWithDelta(40.0, $this->movimentacao->percentualAdministrativo(), 0.001);
        $this->assertEqualsWithDelta(8000.0, $this->movimentacao->getTaxaAdministrativa(), 0.001);
    }

    public function testPartialInvoicePaymentsAcrossMonthsNeverMultiplyEligibleCapital(): void
    {
        $this->persistCategory(id: 45, name: 'Cliente', parentId: null, type: 'income');
        $this->persistCategory(id: 20, name: 'Cliente > Serviço', parentId: 45, type: 'income');

        $document = new Invoice();
        $document->fromArray([
            'id' => 720,
            'type' => 'invoice',
            'issued_at' => '2026-05-03 00:00:00',
            'due_at' => '2026-05-03 00:00:00',
            'transaction_of_month' => '2026-05',
            'amount' => 200.0,
            'discount_percentage' => 0.0,
            'currency_code' => 'BRL',
            'document_number' => 'INVOICE-720',
            'nfse' => null,
            'tax_number' => '12345678000190',
            'customer_reference' => '12345678000190',
            'contact_id' => 12,
            'contact_reference' => null,
            'contact_name' => 'Cliente teste',
            'contact_type' => 'customer',
            'category_id' => 20,
            'category_name' => 'Serviço',
            'category_type' => 'income',
            'archive' => 0,
            'metadata' => [
                'status' => 'partial',
                'items' => ['data' => [['price' => 200.0]]],
                'item_taxes' => ['data' => []],
            ],
        ]);
        $this->entityManager->persist($document);

        $unpaid = clone $document;
        $unpaid->setId(721);
        $unpaid->setDocumentNumber('INVOICE-721');
        $unpaid->setMetadata(['status' => 'sent', 'items' => ['data' => [['price' => 500.0]]]]);
        $unpaid->setAmount(500.0);
        $this->entityManager->persist($unpaid);

        foreach ([
            ['id' => 811, 'date' => '2026-05-08', 'amount' => 30.0],
            ['id' => 813, 'date' => '2026-05-10', 'amount' => 50.0],
            ['id' => 812, 'date' => '2026-06-09', 'amount' => 120.0],
        ] as $payment) {
            $transaction = new Transaction();
            $transaction->fromArray([
                'id' => $payment['id'],
                'type' => 'income',
                'paid_at' => $payment['date'] . ' 00:00:00',
                'transaction_of_month' => substr($payment['date'], 0, 7),
                'amount' => $payment['amount'],
                'discount_percentage' => null,
                'currency_code' => 'BRL',
                'reference' => 'INVOICE-720',
                'nfse' => null,
                'tax_number' => null,
                'customer_reference' => '12345678000190',
                'contact_id' => 12,
                'contact_reference' => null,
                'contact_name' => 'Cliente teste',
                'contact_type' => 'customer',
                'category_id' => 20,
                'category_name' => 'Serviço',
                'category_type' => 'income',
                'archive' => 0,
                'metadata' => ['document_id' => 720],
            ]);
            $this->entityManager->persist($transaction);
        }
        $this->entityManager->flush();

        $this->dates->setInicio(new DateTime('2026-04-01'));
        $may = $this->movimentacao->getEntradasClientes();
        self::assertCount(1, $may);
        self::assertEqualsWithDelta(80.0, array_sum(array_column($may, 'amount')), 0.001);

        $laterDates = static::getContainer()->get(Dates::class);
        $laterDates->setInicio(new DateTime('2026-05-01'));
        $movements = new Movimentacao(
            $this->entityManager,
            new \Psr\Log\NullLogger(),
            $laterDates,
            static::getContainer()->get(\App\Service\Akaunting\Source\Categories::class),
            new \App\Service\Calculation\CustomerCostAllocator(),
        );
        $june = $movements->getEntradasClientes();
        self::assertEqualsWithDelta(120.0, array_sum(array_column($june, 'amount')), 0.001);
        self::assertEqualsWithDelta(200.0, array_sum(array_column($may, 'amount')) + array_sum(array_column($june, 'amount')), 0.001);
    }

    private function persistClientHierarchyRoots(): void
    {
        $this->persistCategory(
            id: (int) getenv('AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'),
            name: 'Cliente > Entradas',
            parentId: 1,
        );
        $this->persistCategory(
            id: (int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'),
            name: 'Cliente > Dispêndios',
            parentId: 1,
        );
    }

    private function persistCategory(int $id, string $name, ?int $parentId, string $type = 'expense'): void
    {
        $category = new Category();
        $category->fromArray([
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'enabled' => 1,
            'parent_id' => $parentId,
            'metadata' => [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'enabled' => true,
                'parent_id' => $parentId,
            ],
        ]);

        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }

    private function persistBill(
        int $id,
        int $categoryId,
        string $categoryName,
        ?string $customerReference,
        string $contactName,
    ): void {
        $invoice = new Invoice();
        $invoice->fromArray([
            'id' => $id,
            'type' => 'bill',
            'issued_at' => '2026-05-04 10:41:39',
            'due_at' => '2026-05-04 10:41:39',
            'transaction_of_month' => '2026-05',
            'amount' => 56.78,
            'discount_percentage' => null,
            'currency_code' => 'BRL',
            'document_number' => sprintf('BILL-%d', $id),
            'nfse' => null,
            'tax_number' => '00000000000000',
            'customer_reference' => $customerReference,
            'contact_id' => $id,
            'contact_reference' => null,
            'contact_name' => $contactName,
            'contact_type' => 'vendor',
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_type' => 'expense',
            'archive' => 0,
            'metadata' => [
                'status' => 'paid',
                'notes' => 'Cooperado: Cooperado Exemplo, CPF: 00011122233, Valor: R$ 56,78',
                'items' => [
                    'data' => [[
                        'name' => 'Serviço',
                        'description' => sprintf('Lançamento legado %d', $id),
                        'price' => 56.78,
                    ]],
                ],
            ],
        ]);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }
}
