<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Domain\Competencia;
use App\Service\Audit\InputManifestBuilder;
use PHPUnit\Framework\TestCase;

final class InputManifestBuilderTest extends TestCase
{
    public function testSameHistoricalInputsHaveStableFingerprintRegardlessOfRowOrder(): void
    {
        $builder = new InputManifestBuilder();
        $period = Competencia::fromString('2024-11');
        $data = [
            'users' => [
                ['id' => 1, 'peso' => 2, 'name' => 'Exemplo A'],
                ['id' => 2, 'peso' => 1, 'name' => 'Exemplo B'],
            ],
            'transactions' => [
                ['id' => 10, 'amount' => '100.00', 'customer_reference' => 'CLIENTE-A'],
                ['id' => 11, 'amount' => '300.00', 'customer_reference' => 'CLIENTE-B'],
            ],
        ];
        $rules = ['PERCENTUAL_MAXIMO' => '25', 'DIA_UTIL_PAGAMENTO' => '5'];
        $first = $builder->build($period, $data, $rules, 'sha123');

        $dataReordered = [
            'transactions' => [
                ['customer_reference' => 'CLIENTE-B', 'amount' => '300.00', 'id' => 11],
                ['customer_reference' => 'CLIENTE-A', 'id' => 10, 'amount' => '100.00'],
            ],
            'users' => array_reverse($data['users']),
        ];
        $second = $builder->build($period, $dataReordered, array_reverse($rules, true), 'sha123');
        self::assertSame($first, $second);
        self::assertSame('2024-11', $first['competencia']);
        self::assertSame('2024-12', $first['financial_month']);
        self::assertSame(2, $first['sources']['transactions']['count']);
        self::assertArrayNotHasKey('amount', $first);
        self::assertStringNotContainsString('CLIENTE-A', json_encode($first, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Exemplo A', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testWeightsAndFinancialMutationsChangeFingerprintWithoutChangingThePeriod(): void
    {
        $builder = new InputManifestBuilder();
        $period = Competencia::fromString('2025-01');
        $original = [
            'users' => [['id' => 9, 'peso' => 1.0]],
            'transactions' => [['id' => 100, 'amount' => '500.00']],
        ];
        $before = $builder->build($period, $original, ['max' => 5], 'r1');
        $newWeights = $original;
        $newWeights['users'][0]['peso'] = 2.0;
        $afterWeight = $builder->build($period, $newWeights, ['max' => 5], 'r1');
        self::assertNotSame($before['weights_sha256'], $afterWeight['weights_sha256']);
        self::assertNotSame($before['sha256'], $afterWeight['sha256']);

        $newMoney = $original;
        $newMoney['transactions'][0]['amount'] = '499.99';
        $afterMoney = $builder->build($period, $newMoney, ['max' => 5], 'r1');
        self::assertNotSame($before['sources']['transactions']['sha256'], $afterMoney['sources']['transactions']['sha256']);
        self::assertSame($before['weights_sha256'], $afterMoney['weights_sha256']);
        self::assertNotSame($before['sha256'], $afterMoney['sha256']);

        self::assertNotSame($before['sha256'], $builder->build($period, $original, ['max' => 10], 'r1')['sha256']);
        self::assertNotSame($before['sha256'], $builder->build($period, $original, ['max' => 5], 'r2')['sha256']);
    }

    public function testUnsafeAmountsAreRejectedInsteadOfBeingHashedAsValidInput(): void
    {
        $this->expectException(\DomainException::class);
        new InputManifestBuilder()->build(
            Competencia::fromString('2025-03'),
            ['transactions' => [['amount' => INF]]]
        );
    }
}
