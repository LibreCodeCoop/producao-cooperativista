<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Command\AuditInputsCommand;
use App\Domain\Competencia;
use App\Service\Audit\InputManifestBuilder;
use App\Service\Audit\StagingInputReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Tests actual SQL partitioning and input changes, never mocks internals. */
final class StagingInputReaderTest extends KernelTestCase
{
    private Connection $main;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->main = static::getContainer()->get('doctrine.dbal.main_connection');
        $this->cleanup();
        $this->main->insert('users', [
            'id' => 9987100,
            'alias' => 'Exemplo',
            'kimai_username' => 'etlauditfixture',
            'tax_number' => '00000000000',
            'dependents' => 0,
            'enabled' => 1,
            'peso' => 1,
            'metadata' => '[]',
        ]);
        $base = [
            'activity_id' => 1,
            'project_id' => 1,
            'user_id' => 9987100,
            'end' => '2024-12-12 09:00:00',
            'duration' => 3600,
            'description' => 'Synthetic activity',
            'rate' => 200,
            'internal_rate' => 100,
            'exported' => 0,
            'billable' => 1,
        ];
        $this->main->insert('timesheet', ['id' => 9987101, ...$base, 'begin' => '2024-12-12 08:00:00']);
        $this->main->insert('timesheet', [
            'id' => 9987102,
            ...array_replace($base, [
                'begin' => '2025-01-12 08:00:00',
                'end' => '2025-01-12 09:00:00',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->main->executeStatement('DELETE FROM timesheet WHERE id IN (9987101, 9987102)');
        $this->main->executeStatement('DELETE FROM users WHERE id = 9987100');
    }

    public function testDecemberHistoricalPartitionDoesNotIncludeJanuaryHours(): void
    {
        $reader = static::getContainer()->get(StagingInputReader::class);
        $december = $reader->read(Competencia::fromString('2024-12'));
        self::assertSame([9987101], array_map(intval(...), array_column($december['timesheet'], 'id')));
        self::assertCount(9, $december);
        $january = $reader->read(Competencia::fromString('2025-01'));
        self::assertSame([9987102], array_map(intval(...), array_column($january['timesheet'], 'id')));
    }

    public function testChangingAnImportedHourOrWeightChangesThePeriodFingerprint(): void
    {
        $reader = static::getContainer()->get(StagingInputReader::class);
        $builder = new InputManifestBuilder();
        $period = Competencia::fromString('2024-12');
        $before = $builder->build($period, $reader->read($period), [], 'commit-fixture');

        $this->main->executeStatement('UPDATE timesheet SET duration = 7200 WHERE id = 9987101');
        $afterHours = $builder->build($period, $reader->read($period), [], 'commit-fixture');
        self::assertNotSame($before['sources']['timesheet']['sha256'], $afterHours['sources']['timesheet']['sha256']);
        self::assertSame($before['weights_sha256'], $afterHours['weights_sha256']);

        $this->main->executeStatement('UPDATE users SET peso = 2 WHERE id = 9987100');
        $afterWeights = $builder->build($period, $reader->read($period), [], 'commit-fixture');
        self::assertSame($afterHours['sources']['timesheet']['sha256'], $afterWeights['sources']['timesheet']['sha256']);
        self::assertNotSame($afterHours['weights_sha256'], $afterWeights['weights_sha256']);
        self::assertNotSame($before['sha256'], $afterWeights['sha256']);
    }

    public function testOptionalPrivateBundleRetainsImmutableInputsForFutureReview(): void
    {
        $path = dirname(__DIR__, 4) . '/var/audit/2024-12';
        $before = glob($path . '/*.json') ?: [];

        $tester = new CommandTester(static::getContainer()->get(AuditInputsCommand::class));
        $status = $tester->execute([
            '--ano-mes' => '2024-12',
            '--revision' => 'fixture-revision',
            '--save-bundle' => true,
        ]);
        self::assertSame(Command::SUCCESS, $status);

        $after = glob($path . '/*.json') ?: [];
        $created = array_values(array_diff($after, $before));
        self::assertCount(1, $created);
        try {
            self::assertSame(0600, fileperms($created[0]) & 0777);
            $bundle = json_decode(file_get_contents($created[0]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2024-12', $bundle['manifest']['competencia']);
            self::assertSame('librecode', $bundle['rules']['__profile_id']);
            self::assertSame('librecode', $bundle['rules']['__allocation_policy_id']);
            self::assertStringContainsString('akaunting.documents', $bundle['rules']['__selected_sources']);
            self::assertSame(
                $bundle['manifest'],
                new InputManifestBuilder()->build(
                    Competencia::fromString('2024-12'),
                    $bundle['sources'],
                    $bundle['rules'],
                    $bundle['revision']
                )
            );
            self::assertSame(9987101, (int) $bundle['sources']['timesheet'][0]['id']);
        } finally {
            unlink($created[0]);
        }
    }

    public function testAuditCommandReportsInputIdentityWithoutWritingToAkaunting(): void
    {
        $command = static::getContainer()->get(AuditInputsCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute(['--ano-mes' => '2024-12', '--revision' => 'fixture-revision']);
        self::assertSame(Command::SUCCESS, $status);
        $manifest = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2024-12', $manifest['competencia']);
        self::assertSame('2025-01', $manifest['financial_month']);
        self::assertSame('fixture-revision', $manifest['revision']);
        self::assertSame(1, $manifest['sources']['timesheet']['count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['sha256']);
        self::assertStringNotContainsString('Synthetic activity', $tester->getDisplay());
    }
}
