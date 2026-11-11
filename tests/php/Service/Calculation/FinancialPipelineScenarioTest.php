<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Entity\Producao\Category;
use App\Entity\Producao\Invoice;
use App\Entity\Producao\Transaction;
use App\Helper\Dates;
use App\Service\Calculation\ProductionDistributionPlanner;
use App\Service\Movimentacao;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real ETL staging SQL -> eligible capital -> weighted production.
 * No mocked private methods, no bank balances, no writes to Akaunting.
 */
final class FinancialPipelineScenarioTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->manager = $container->get(EntityManagerInterface::class);
        $db = $this->manager->getConnection();
        foreach (['transactions', 'invoices', 'categories'] as $table) {
            $db->executeStatement('DELETE FROM ' . $table);
        }
        $this->manager->clear();

        foreach ([
            [45, 'Cliente > Receitas', null, 'income'],
            [20, 'Cliente > Serviços', 45, 'income'],
            [16, 'Cooperativa > Despesas administrativas', null, 'expense'],
        ] as [$id, $name, $parent, $type]) {
            $entity = new Category();
            $entity->fromArray([
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'enabled' => 1,
                'parent_id' => $parent,
                'metadata' => [],
            ]);
            $this->manager->persist($entity);
        }

        // Paid invoices without linked transaction are accepted; a merely
        // issued invoice is not eligible financial capital.
        $this->invoice(101, '2026-05', 12000, 'paid');
        $this->invoice(102, '2026-05', 75000, 'sent');
        $this->invoice(103, '2026-06', 2000, 'paid');

        $expense = new Transaction();
        $expense->fromArray([
            'id' => 501,
            'type' => 'expense',
            'paid_at' => '2026-05-05 09:00:00',
            'transaction_of_month' => '2026-05',
            'amount' => 2000,
            'discount_percentage' => 0,
            'currency_code' => 'BRL',
            'reference' => 'Custo operacional',
            'nfse' => null,
            'tax_number' => null,
            'customer_reference' => null,
            'contact_id' => 100,
            'contact_reference' => null,
            'contact_name' => 'Fornecedor',
            'contact_type' => 'vendor',
            'category_id' => 16,
            'category_name' => 'Cooperativa > Despesas administrativas',
            'category_type' => 'expense',
            'archive' => 0,
            'metadata' => [],
        ]);
        $this->manager->persist($expense);
        $this->manager->flush();
    }

    private function invoice(int $id, string $financialMonth, float $amount, string $status): void
    {
        $invoice = new Invoice();
        $invoice->fromArray([
            'id' => $id,
            'type' => 'invoice',
            'issued_at' => $financialMonth . '-01 09:00:00',
            'due_at' => $financialMonth . '-01 09:00:00',
            'transaction_of_month' => $financialMonth,
            'amount' => $amount,
            'discount_percentage' => 0,
            'currency_code' => 'BRL',
            'document_number' => 'TEST-' . $id,
            'nfse' => null,
            'tax_number' => null,
            'customer_reference' => '12345678000190',
            'contact_id' => 12,
            'contact_reference' => null,
            'contact_name' => 'Cliente',
            'contact_type' => 'customer',
            'category_id' => 20,
            'category_name' => 'Cliente > Serviços',
            'category_type' => 'income',
            'archive' => 0,
            'metadata' => [
                'status' => $status,
                'items' => ['data' => [['price' => $amount]]],
                'item_taxes' => ['data' => []],
            ],
        ]);
        $this->manager->persist($invoice);
    }

    public function testPaidRevenueMinusAdministrativeReserveDistributesWithWeights(): void
    {
        $dates = static::getContainer()->get(Dates::class);
        $dates->setInicio(new DateTime('2026-04-01'));
        $movement = static::getContainer()->get(Movimentacao::class);
        $movement->setPercentualMaximo(50);

        $incomes = $movement->getEntradasClientes();
        self::assertCount(1, $incomes);
        self::assertEqualsWithDelta(12000.0, $movement->getTotalNotasClientes(), 0.001);
        self::assertEqualsWithDelta(4000.0, $movement->getTaxaAdministrativa(), 0.001);
        self::assertEqualsWithDelta(8000.0, $movement->getBaseProducao(), 0.001);

        $planner = static::getContainer()->get(ProductionDistributionPlanner::class);
        $work = [
            ['tax_number' => 'member-a', 'customer_reference' => '12345678000190',
             'trabalhado' => 14400, 'peso' => 2, 'total_cliente' => 28800],
            ['tax_number' => 'member-b', 'customer_reference' => '12345678000190',
             'trabalhado' => 14400, 'peso' => 1, 'total_cliente' => 28800],
        ];
        $distribution = $planner->plan($incomes, $work);
        self::assertEqualsWithDelta(8000.0, array_sum($distribution->amounts), 0.001);
        self::assertSame(5333.33, $distribution->amounts['member-a']);
        self::assertSame(2666.67, $distribution->amounts['member-b']);
        self::assertSame(800000, array_sum(array_map(
            \App\Service\Calculation\DecimalAllocation::cents(...),
            $distribution->amounts,
        )));
    }
}
