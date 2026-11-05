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
        $calls = 0;
        $dataset->expects($this->exactly(2))->method('list')
            ->willReturnCallback(static function (string $endpoint, array $query) use (&$calls): array {
                ++$calls;
                if ($calls === 1) {
                    self::assertSame('/api/v1/field-studio/records', $endpoint);
                    self::assertSame('2025-03', $query['value']);
                    self::assertSame('invoice', $query['entity_type']);
                    return [1546, 1553];
                }
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
        $result = (new FinancialMonthScope())->complete($dataset, $original, 'invoice', '2025-03', 1);
        self::assertSame([1553, 1546], array_column($result, 'id'));
    }

    public function testMissingDefinitionDataDoesNotAllowDateBasedGuessing(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->exactly(2))->method('list')
            ->willReturnOnConsecutiveCalls([1546], [['id' => 1546, 'company_id' => 1, 'type' => 'invoice']]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Inconsistent financial-month lookup');
        (new FinancialMonthScope())->complete($dataset, [], 'invoice', '2025-03', 1);
    }

    public function testExactCapFailsInsteadOfSilentlyTruncating(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn(range(1, 200));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limite de registros');
        (new FinancialMonthScope())->complete($dataset, [], 'invoice', '2025-03', 1);
    }

    public function testDoesNotInventAnEarlierCompetenceForUnassignedRecords(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn([]);
        $rows = [['id' => 5, 'type' => 'transaction']];
        self::assertSame($rows, (new FinancialMonthScope())->complete(
            $dataset,
            $rows,
            'transaction',
            '2026-10',
            1
        ));
    }
}
