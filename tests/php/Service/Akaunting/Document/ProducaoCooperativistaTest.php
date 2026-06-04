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

namespace App\Tests\Service\Akaunting\Document;

use App\Entity\Producao\Category;
use App\Entity\Producao\Invoice;
use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\ProducaoCooperativista;
use App\Service\Akaunting\Source\Documents;
use App\Service\Cooperado;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
use Tests\Php\TestCase;

final class ProducaoCooperativistaTest extends TestCase
{
    private const COOPERADO_NOME = 'Cooperado Exemplo';
    private const COOPERADO_CPF = '00011122233';
    private const VALOR_BENEFICIO_SECUNDARIO = 12.34;
    private const VALOR_BENEFICIO_PRINCIPAL = 56.78;
    private const VALOR_TELEFONIA = 7.89;
    private const TELEFONIA_CATEGORY_ID = 9100;

    private EntityManagerInterface $entityManager;
    private Dates $dates;
    private Documents $documents;
    private Request $request;
    private NumberFormatter $numberFormatter;

    protected function setUp(): void
    {
        static::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->dates = $container->get(Dates::class);
        $this->documents = $container->get(Documents::class);
        $this->request = $container->get(Request::class);
        $this->numberFormatter = new NumberFormatter((string) getenv('LOCALE'), NumberFormatter::CURRENCY);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM invoices');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM categories');
        $this->entityManager->clear();

        $this->dates->setInicio(new DateTime('2026-04-01 00:00:00'));
    }

    public function testUpdateLiquidDiscountsSumsLatestMatchPerChildCategory(): void
    {
        $this->persistLiquidDiscountCategoryHierarchy();

        $this->persistBill(
            id: 1001,
            amount: self::VALOR_BENEFICIO_SECUNDARIO,
            notes: 'Cooperado: ' . self::COOPERADO_NOME . ', CPF: ' . self::COOPERADO_CPF . ', Valor: R$ 12,34',
            categoryId: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            categoryName: 'Cooperado > Produção > Desconto líquido > Plano de saúde',
            itemDescription: 'Lançamento genérico 1001',
            contactName: 'Fornecedor Exemplo A'
        );
        $this->persistBill(
            id: 1002,
            amount: self::VALOR_BENEFICIO_PRINCIPAL,
            notes: 'Cooperado: ' . self::COOPERADO_NOME . ', CPF: ' . self::COOPERADO_CPF . ', Valor: R$ 56,78',
            categoryId: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            categoryName: 'Cooperado > Produção > Desconto líquido > Plano de saúde',
            itemDescription: 'Lançamento genérico 1002',
            contactName: 'Fornecedor Exemplo B'
        );
        $this->persistBill(
            id: 1003,
            amount: self::VALOR_TELEFONIA,
            notes: 'Cooperado: ' . self::COOPERADO_NOME . ', CPF: ' . self::COOPERADO_CPF . ', Valor: R$ 7,89',
            categoryId: self::TELEFONIA_CATEGORY_ID,
            categoryName: 'Cooperado > Produção > Desconto líquido > Telefonia',
            itemDescription: 'Lançamento genérico 1003',
            contactName: 'Fornecedor Exemplo C'
        );

        $document = $this->createDocumentForCooperado(self::COOPERADO_NOME, self::COOPERADO_CPF);
        $document->updateLiquidDiscounts();

        $this->assertSame(64.67, round($document->getValues()->getHealthInsurance(), 2));
    }

    public function testUpdateLiquidDiscountsFallsBackToOlderBillInSameCategoryWhenLatestCpfDoesNotMatch(): void
    {
        $this->persistLiquidDiscountCategoryHierarchy();

        $this->persistBill(
            id: 1003,
            amount: self::VALOR_BENEFICIO_SECUNDARIO,
            notes: 'Cooperado: ' . self::COOPERADO_NOME . ', CPF: ' . self::COOPERADO_CPF . ', Valor: R$ 12,34',
            categoryId: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            categoryName: 'Cooperado > Produção > Desconto líquido > Plano de saúde',
            itemDescription: 'Lançamento genérico 1003',
            contactName: 'Fornecedor Exemplo B'
        );
        $this->persistBill(
            id: 1004,
            amount: self::VALOR_BENEFICIO_PRINCIPAL,
            notes: 'Cooperado: Outro Cooperado, CPF: 99988877766, Valor: R$ 56,78',
            categoryId: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            categoryName: 'Cooperado > Produção > Desconto líquido > Plano de saúde',
            itemDescription: 'Lançamento genérico 1004',
            contactName: 'Fornecedor Exemplo C'
        );

        $document = $this->createDocumentForCooperado(self::COOPERADO_NOME, self::COOPERADO_CPF);
        $document->updateLiquidDiscounts();

        $this->assertSame(12.34, round($document->getValues()->getHealthInsurance(), 2));
    }

    public function testUpdateLiquidDiscountsSupportsLegacyHealthInsuranceCategoryDuringTransition(): void
    {
        $this->persistBill(
            id: 1005,
            amount: self::VALOR_BENEFICIO_PRINCIPAL,
            notes: 'Cooperado: ' . self::COOPERADO_NOME . ', CPF: ' . self::COOPERADO_CPF . ', Valor: R$ 56,78',
            categoryId: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            categoryName: 'Cooperado > Produção > Plano de saúde',
            itemDescription: 'Lançamento legado 1005',
            contactName: 'Fornecedor Legado'
        );

        $document = $this->createDocumentForCooperado(self::COOPERADO_NOME, self::COOPERADO_CPF);
        $document->updateLiquidDiscounts();

        $this->assertSame(56.78, round($document->getValues()->getHealthInsurance(), 2));
    }

    private function createDocumentForCooperado(string $name, string $taxNumber): ProducaoCooperativista
    {
        $cooperado = new Cooperado(
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            request: $this->request,
            anoFiscal: 2026,
            mes: 4,
        );
        $cooperado
            ->setName($name)
            ->setTaxNumber($taxNumber)
            ->setAkauntingContactId(172)
            ->setDependentes(0)
            ->setWeight(1);

        return $cooperado->getProducaoCooperativista();
    }

    private function persistLiquidDiscountCategoryHierarchy(): void
    {
        $parentCategoryId = (int) getenv('AKAUNTING_PARENT_DESCONTO_LIQUIDO_CATEGORY_ID');
        $this->persistCategory(
            id: $parentCategoryId,
            name: 'Cooperado > Produção > Desconto líquido',
            parentId: 11,
        );
        $this->persistCategory(
            id: (int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'),
            name: 'Cooperado > Produção > Desconto líquido > Plano de saúde',
            parentId: $parentCategoryId,
        );
        $this->persistCategory(
            id: self::TELEFONIA_CATEGORY_ID,
            name: 'Cooperado > Produção > Desconto líquido > Telefonia',
            parentId: $parentCategoryId,
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
        float $amount,
        string $notes,
        int $categoryId,
        string $categoryName,
        string $itemDescription,
        string $contactName
    ): void
    {
        $invoice = new Invoice();
        $invoice->fromArray([
            'id' => $id,
            'type' => 'bill',
            'issued_at' => '2026-05-04 10:41:39',
            'due_at' => '2026-05-04 10:41:39',
            'transaction_of_month' => '2026-05',
            'amount' => $amount,
            'discount_percentage' => null,
            'currency_code' => 'BRL',
            'document_number' => sprintf('BILL-%d', $id),
            'nfse' => null,
            'tax_number' => '00000000000000',
            'customer_reference' => null,
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
                'notes' => $notes,
                'items' => [
                    'data' => [[
                        'name' => 'Serviço',
                        'description' => $itemDescription,
                        'price' => $amount,
                    ]],
                ],
            ],
        ]);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }
}
