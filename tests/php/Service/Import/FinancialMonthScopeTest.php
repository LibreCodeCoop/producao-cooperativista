<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Provider\Akaunting\Dataset;
use App\Service\Import\FinancialMonthScope;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FinancialMonthScopeTest extends TestCase
{
    public function testFetchesInvoiceMissingFromNativeDueDateWindow(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('recordIds')
            ->with('invoice', '2025-03', 1, 200)->willReturn([1546, 1553]);
        $dataset->expects($this->once())->method('list')
            ->willReturnCallback(static function (string $endpoint, array $query): array {
                self::assertSame('/api/documents', $endpoint);
                self::assertSame('type:invoice id:1546', $query['search']);
                self::assertSame('1', $query['include_field_studio']);
                return [[
                    'id' => 1546, 'company_id' => 1, 'type' => 'invoice',
                    'field_studio' => ['transaction_of_month' => ['is_set' => true, 'value' => '2025-03']],
                ]];
            });
        $original = [[
            'id' => 1553, 'company_id' => 1, 'type' => 'invoice',
            'field_studio' => ['transaction_of_month' => ['is_set' => true, 'value' => '2025-03']],
        ]];
        $result = new FinancialMonthScope()->complete($dataset, $original, 'invoice', '2025-03', 1);
        self::assertSame([1553, 1546], array_column($result, 'id'));
    }

    public function testMissingDefinitionDataDoesNotAllowDateBasedGuessing(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('recordIds')->willReturn([1546]);
        $dataset->expects($this->once())->method('list')
            ->willReturn([['id' => 1546, 'company_id' => 1, 'type' => 'invoice']]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Inconsistent financial-month lookup');
        new FinancialMonthScope()->complete($dataset, [], 'invoice', '2025-03', 1);
    }

    public function testExactCapFailsInsteadOfSilentlyTruncating(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('recordIds')->willReturn(range(1, 200));
        $dataset->expects($this->never())->method('list');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limite de registros');
        new FinancialMonthScope()->complete($dataset, [], 'invoice', '2025-03', 1);
    }

    public function testDoesNotInventAnEarlierCompetenceForUnassignedRecords(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('recordIds')->willReturn([]);
        $dataset->expects($this->never())->method('list');
        $rows = [['id' => 5, 'type' => 'transaction']];
        self::assertSame($rows, new FinancialMonthScope()->complete(
            $dataset,
            $rows,
            'transaction',
            '2026-10',
            1
        ));
    }
}
