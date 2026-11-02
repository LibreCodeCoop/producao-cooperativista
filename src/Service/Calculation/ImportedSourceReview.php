<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use App\Domain\Competencia;
use App\Service\Akaunting\Source\Categories;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only reconciliation of the staged sources. This is deliberately not a
 * substitute for the financial settlement and never returns a publish revision.
 */
final readonly class ImportedSourceReview
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony dependency injection. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $database,
        private Categories $categories,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(Competencia $competence): array
    {
        $start = $competence->start();
        $workMonth = (string) $competence;
        $financialMonth = $competence->financialMonth();
        $work = $this->database->fetchAssociative(
            'SELECT COUNT(*) AS entries, COUNT(DISTINCT user_id) AS members, '
            . 'COALESCE(SUM(duration), 0) AS seconds FROM timesheet '
            . 'WHERE begin >= :start AND begin < :end',
            ['start' => $start->format('Y-m-d'), 'end' => $competence->endExclusive()->format('Y-m-d')],
        ) ?: [];

        $income = $this->categoryIds('AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID');
        $clientCosts = $this->categoryIds('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID');
        $internal = $this->categoryIds('AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID');

        $movements = $this->database->fetchAllAssociative(
            'SELECT id, type, category_id, category_name, customer_reference, amount, metadata '
            . 'FROM transactions WHERE transaction_of_month = :month AND archive = 0 ORDER BY id',
            ['month' => $financialMonth],
        );

        $paidDocumentIds = [];
        $incomeTotal = 0.0;
        $incomeCount = 0;
        $clientCostTotal = 0.0;
        $internalTotal = 0.0;
        $costGroups = [];
        $internalGroups = [];
        foreach ($movements as $movement) {
            $meta = json_decode((string) ($movement['metadata'] ?? ''), true);
            if (is_array($meta) && is_numeric($meta['document_id'] ?? null)) {
                $paidDocumentIds[(int) $meta['document_id']] = true;
            }
            $id = (int) ($movement['category_id'] ?? 0);
            $amount = (float) $movement['amount'];
            if ($movement['type'] === 'income' && isset($income[$id])) {
                $incomeTotal += $amount;
                ++$incomeCount;
            } elseif ($movement['type'] === 'expense' && isset($clientCosts[$id])) {
                $clientCostTotal += $amount;
                $reference = trim((string) ($movement['customer_reference'] ?? '')) ?: '(sem referência)';
                $costGroups[$reference] = ($costGroups[$reference] ?? 0.0) + $amount;
            } elseif ($movement['type'] === 'expense' && isset($internal[$id])) {
                $internalTotal += $amount;
                $name = trim((string) ($movement['category_name'] ?? '')) ?: 'Categoria #' . $id;
                $internalGroups[$name] = ($internalGroups[$name] ?? 0.0) + $amount;
            }
        }

        $documents = $this->database->fetchAllAssociative(
            "SELECT id, document_number, amount, due_at, category_id, metadata "
            . "FROM invoices WHERE transaction_of_month = :month AND type = 'invoice' "
            . "AND category_type = 'income' AND archive = 0 ORDER BY id",
            ['month' => $financialMonth],
        );
        $unconfirmed = [];
        $unconfirmedCount = 0;
        $unconfirmedAmount = 0.0;
        foreach ($documents as $document) {
            if (!isset($income[(int) $document['category_id']])) {
                continue;
            }
            $id = (int) $document['id'];
            $metadata = json_decode((string) ($document['metadata'] ?? ''), true);
            $status = is_array($metadata) ? (string) ($metadata['status'] ?? 'unknown') : 'unknown';
            if ($status === 'paid' || isset($paidDocumentIds[$id])) {
                continue;
            }
            ++$unconfirmedCount;
            $unconfirmedAmount += (float) $document['amount'];
            if (count($unconfirmed) < 100) {
                $unconfirmed[] = [
                    'id' => $id,
                    'number' => (string) ($document['document_number'] ?? ''),
                    'amount' => round((float) $document['amount'], 2),
                    'status' => $status,
                    'due_date' => substr((string) ($document['due_at'] ?? ''), 0, 10),
                ];
            }
        }
        ksort($costGroups);
        ksort($internalGroups);

        return [
            'competence' => $workMonth,
            'financial_month' => $financialMonth,
            'publication_allowed' => false,
            'hours' => [
                'entries' => (int) ($work['entries'] ?? 0),
                'members' => (int) ($work['members'] ?? 0),
                'seconds' => (int) ($work['seconds'] ?? 0),
            ],
            'income_transactions' => ['count' => $incomeCount, 'amount' => round($incomeTotal, 2)],
            'client_cost_transactions' => [
                'amount' => round($clientCostTotal, 2),
                'by_customer' => $this->moneyGroups($costGroups),
            ],
            'internal_expense_transactions' => [
                'amount' => round($internalTotal, 2),
                'by_category' => $this->moneyGroups($internalGroups),
            ],
            'unconfirmed_invoices' => [
                'count' => $unconfirmedCount,
                'amount' => round($unconfirmedAmount, 2),
                'documents' => $unconfirmed,
                'truncated' => $unconfirmedCount > count($unconfirmed),
            ],
        ];
    }

    /** @return array<int, true> */
    private function categoryIds(string $key): array
    {
        $parent = filter_var(getenv($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parent === false) {
            throw new \DomainException('Categorias financeiras de auditoria não configuradas.');
        }
        return array_fill_keys(array_map(intval(...), $this->categories->getChildrensCategories($parent)), true);
    }

    /** @param array<string, float> $groups
     *  @return list<array{label: string, amount: float}>
     */
    private function moneyGroups(array $groups): array
    {
        $result = [];
        foreach ($groups as $name => $amount) {
            $result[] = ['label' => (string) $name, 'amount' => round($amount, 2)];
        }
        return $result;
    }
}
