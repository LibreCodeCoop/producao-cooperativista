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

    private function persistCategory(int $id, string $name, ?int $parentId): void
    {
        $category = new Category();
        $category->fromArray([
            'id' => $id,
            'name' => $name,
            'type' => 'expense',
            'enabled' => 1,
            'parent_id' => $parentId,
            'metadata' => [
                'id' => $id,
                'name' => $name,
                'type' => 'expense',
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
