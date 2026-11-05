<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Domain\Competencia;
use App\Service\Audit\InputBundleVerifier;
use App\Service\Audit\InputManifestBuilder;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InputBundleVerifierTest extends TestCase
{
    private string $root;
    private string $file;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pdc-test-bundle-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/audit/2026-08', 0700, true));
        $this->file = $this->root . '/audit/2026-08/bundle.json';
        $sources = ['users' => [['id' => 7, 'peso' => 1]], 'transactions' => [['id' => 5, 'amount' => '25.00']]];
        $rules = ['PERCENTUAL_MAXIMO' => '25'];
        $revision = 'reviewed-sha';
        $manifest = new InputManifestBuilder()->build(Competencia::fromString('2026-08'), $sources, $rules, $revision);
        file_put_contents($this->file, json_encode([
            'manifest' => $manifest, 'rules' => $rules, 'revision' => $revision, 'sources' => $sources,
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        // Only this test's own random temporary directory is removed.
        if (is_file($this->file)) {
            unlink($this->file);
        }
        rmdir($this->root . '/audit/2026-08');
        rmdir($this->root . '/audit');
        rmdir($this->root);
    }

    public function testVerifiesOriginalInputBundle(): void
    {
        $result = new InputBundleVerifier(new InputManifestBuilder())->verify($this->file, $this->root . '/audit');
        self::assertSame('2026-08', $result['competencia']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['sha256']);
    }

    public function testRejectsModifiedFinancialSource(): void
    {
        $bundle = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        $bundle['sources']['transactions'][0]['amount'] = '2500.00';
        file_put_contents($this->file, json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Integridade');
        new InputBundleVerifier(new InputManifestBuilder())->verify($this->file, $this->root . '/audit');
    }

    public function testRejectsBundleOutsidePrivateAuditDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        new InputBundleVerifier(new InputManifestBuilder())->verify($this->file, $this->root . '/another');
    }
}
