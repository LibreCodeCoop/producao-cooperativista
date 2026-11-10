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
    private static function fields(array $values = []): array
    {
        $result = [];
        foreach ([
            'transaction_of_month', 'discount_percentage', 'customer_cnpj',
            'sector', 'archive', 'nfse', 'production_treatment',
        ] as $key) {
            $result[$key] = [
                'is_set' => array_key_exists($key, $values),
                'value' => $values[$key] ?? null,
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

    public function testRecordWithoutHistoricalMigrationMarkerIsAccepted(): void
    {
        $result = new FieldStudio()->fromRecords('transaction', [[
            'id' => 91, 'company_id' => 1,
            'field_studio' => self::fields(['transaction_of_month' => '2026-10']),
        ]], 1);
        self::assertSame(['transaction_of_month' => '2026-10'], $result[91]);
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

    public function testProductionTreatmentIsValidatedAndIndependentOfLegacyArchive(): void
    {
        $result = new FieldStudio()->fromRecords('invoice', [[
            'id' => 90, 'type' => 'invoice', 'company_id' => 1,
            'field_studio' => self::fields([
                'archive' => '1', 'production_treatment' => 'incluir_pendente',
            ]),
        ]], 1);
        self::assertSame('sim', $result[90]['archive']);
        self::assertSame('incluir_pendente', $result[90]['production_treatment']);
    }

    public function testUiPortugueseOptionIsNormalizedToStableBusinessValue(): void
    {
        $result = new FieldStudio()->fromRecords('invoice', [[
            'id' => 90, 'type' => 'invoice', 'company_id' => 1,
            'field_studio' => self::fields(['production_treatment' => 'Incluir mesmo sem pagamento']),
        ]], 1);
        self::assertSame('incluir_pendente', $result[90]['production_treatment']);
    }

    public function testInvalidProductionTreatmentFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid production treatment');
        new FieldStudio()->fromRecords('transaction', [[
            'id' => 90, 'company_id' => 1,
            'field_studio' => self::fields(['production_treatment' => 'unexpected']),
        ]], 1);
    }

    public function testEmptyBatchRequiresNoMetadata(): void
    {
        self::assertSame([], new FieldStudio()->fromRecords('invoice', [], 1));
    }
}
