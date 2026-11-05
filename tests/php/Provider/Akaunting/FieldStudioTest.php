<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Provider\Akaunting;

use App\Provider\Akaunting\FieldStudio;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FieldStudioTest extends TestCase
{
    /** @return array<string, array{value:?string,is_set:bool}> */
    private static function fields(array $values = [], bool $migrated = true): array
    {
        $result = [];
        foreach ([
            'transaction_of_month', 'discount_percentage', 'customer_cnpj',
            'sector', 'archive', 'nfse', 'production_metadata_migrated',
        ] as $key) {
            $isSet = array_key_exists($key, $values) || ($key === 'production_metadata_migrated' && $migrated);
            $result[$key] = [
                'is_set' => $isSet,
                'value' => $key === 'production_metadata_migrated' && $migrated
                    ? '1' : ($values[$key] ?? null),
            ];
        }
        return $result;
    }

    public function testStructuredApiValuesOverrideContradictoryFreeText(): void
    {
        $result = new FieldStudio()->fromRecords('invoice', [[
            'id' => 10, 'type' => 'invoice', 'company_id' => 1,
            'notes' => "Transação do mês: 2026-12\nCNPJ cliente: 99999999000199",
            'field_studio' => self::fields([
                'transaction_of_month' => '2026-10',
                'customer_cnpj' => '00000000000191',
                'sector' => 'Ecidade',
                'archive' => '0',
                'discount_percentage' => '29.000000',
                'nfse' => '0056',
            ]),
        ], [
            'id' => 11, 'type' => 'invoice', 'company_id' => 1,
            'field_studio' => self::fields(),
        ]], 1);
        self::assertSame([
            'transaction_of_month' => '2026-10',
            'discount_percentage' => '29.000000',
            'customer' => '00000000000191',
            'sector' => 'ecidade',
            'archive' => 'nao',
            'nfse' => '0056',
        ], $result[10]);
        self::assertSame([], $result[11]);
    }

    public function testUnmigratedRecordFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not migrated');
        new FieldStudio()->fromRecords('transaction', [[
            'id' => 91, 'company_id' => 1,
            'field_studio' => self::fields(['transaction_of_month' => '2026-10'], false),
        ]], 1);
    }

    public function testMissingSchemaFailsClosed(): void
    {
        $fields = self::fields();
        unset($fields['transaction_of_month']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('schema incomplete');
        new FieldStudio()->fromRecords('bill', [[
            'id' => 91, 'type' => 'bill', 'company_id' => 1, 'field_studio' => $fields,
        ]], 1);
    }

    public function testMissingApiPayloadDoesNotUsePaymentDate(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Missing Field Studio API data');
        new FieldStudio()->fromRecords('transaction', [[
            'id' => 91, 'company_id' => 1, 'paid_at' => '2026-10-02',
        ]], 1);
    }

    public function testInvalidFinancialMonthIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid structured financial month');
        new FieldStudio()->fromRecords('invoice', [[
            'id' => 91, 'type' => 'invoice', 'company_id' => 1,
            'field_studio' => self::fields(['transaction_of_month' => '2026-13']),
        ]], 1);
    }

    public function testCrossCompanyRecordIsRejected(): void
    {
        $this->expectException(DomainException::class);
        new FieldStudio()->fromRecords('invoice', [[
            'id' => 91, 'type' => 'invoice', 'company_id' => 2,
            'field_studio' => self::fields(),
        ]], 1);
    }

    public function testEmptyBatchRequiresNoMetadata(): void
    {
        self::assertSame([], new FieldStudio()->fromRecords('invoice', [], 1));
    }
}
