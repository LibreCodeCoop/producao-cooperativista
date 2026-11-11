<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Policy;

use App\Command\AllocationPreviewCommand;
use App\Command\MakeProducaoCommand;
use App\Domain\Competencia;
use App\Service\Kimai\Source\WorkMetricsReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage of real staging SQL -> Kimai work unit -> proportional
 * business distribution, with all financial API writers unavailable.
 */
final class PolicyPreviewScenarioTest extends KernelTestCase
{
    private Connection $connection;
    private string|false $oldProfile;
    private array $files = [];

    protected function setUp(): void
    {
        $this->oldProfile = getenv('COOPERATIVE_PROFILE');
        putenv('COOPERATIVE_PROFILE=hours');
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine.dbal.main_connection');
        $this->cleanup();

        $this->connection->insert('customers', [
            'id' => 994401,
            'name' => 'Cliente ilustrativo',
            'number' => null,
            'comment' => null,
            'visible' => 1,
            'billable' => 1,
            'currency' => 'BRL',
            'color' => null,
            'vat_id' => 'CLIENTE-TESTE',
            'time_budget' => 0,
        ]);
        $this->connection->insert('projects', [
            'id' => 994402,
            'parent_title' => 'Projeto',
            'customer_id' => 994401,
            'name' => 'Projeto de exemplo',
            'start' => null,
            'end' => null,
            'comment' => null,
            'visible' => 1,
            'billable' => 1,
            'color' => null,
            'global_activities' => 1,
            'time_budget' => 0,
        ]);
        foreach ([994403, 994404] as $id) {
            $this->connection->insert('users', [
                'id' => $id,
                'alias' => 'Profissional ' . $id,
                'kimai_username' => 'profissional' . $id,
                'akaunting_contact_id' => null,
                'tax_number' => null,
                'dependents' => 0,
                'enabled' => 1,
                'peso' => null,
                'metadata' => '[]',
            ]);
        }
        $base = [
            'activity_id' => 1,
            'project_id' => 994402,
            'description' => 'Atividade de trabalho fictícia',
            'rate' => 50,
            'internal_rate' => 20,
            'exported' => 0,
            'billable' => 1,
        ];
        foreach ([
            [994405, 994403, '2026-07-02', 3600, 1],
            [994406, 994404, '2026-07-03', 10800, 1],
            [994407, 994404, '2026-08-03', 3600, 1],
            [994408, 994403, '2026-07-04', 7200, 0],
        ] as [$id, $user, $day, $duration, $billable]) {
            $this->connection->insert('timesheet', [
                'id' => $id,
                ...$base,
                'user_id' => $user,
                'begin' => $day . ' 08:00:00',
                'end' => $day . ' 10:00:00',
                'duration' => $duration,
                'billable' => $billable,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if ($this->oldProfile === false) {
            putenv('COOPERATIVE_PROFILE');
        } else {
            putenv('COOPERATIVE_PROFILE=' . $this->oldProfile);
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $this->connection->executeStatement('DELETE FROM timesheet WHERE id BETWEEN 994405 AND 994408');
        $this->connection->executeStatement('DELETE FROM users WHERE id IN (994403, 994404)');
        $this->connection->executeStatement('DELETE FROM projects WHERE id = 994402');
        $this->connection->executeStatement('DELETE FROM customers WHERE id = 994401');
    }

    private function receiptFile(array $receipts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'approved-receipts-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($receipts, JSON_THROW_ON_ERROR));
        $this->files[] = $path;
        return $path;
    }

    private function preview(string $metric, array $receipts): array
    {
        $tester = new CommandTester(static::getContainer()->get(AllocationPreviewCommand::class));
        $status = $tester->execute([
            '--ano-mes' => '2026-07',
            '--receipts-file' => $this->receiptFile($receipts),
            '--metric' => $metric,
        ]);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMultipleMetricsProduceDifferentCorrectResultsWithoutMoneyWrites(): void
    {
        $receipts = [
            ['customer_reference' => 'CLIENTE-TESTE', 'eligible_amount' => 1200, 'currency' => 'BRL'],
        ];

        $hours = $this->preview('elapsed-hours', $receipts);
        self::assertSame('hours', $hours['profile']);
        self::assertSame('hours-proportional', $hours['policy']);
        self::assertSame('disabled', $hours['publication']);
        self::assertSame('2026-07', $hours['competence']);
        self::assertEqualsWithDelta(1200, $hours['eligible_total'], 0.00001);
        self::assertEqualsWithDelta(1200, $hours['allocated_total'], 0.00001);
        self::assertEqualsWithDelta(300, $hours['amounts']['kimai:994403'], 0.00001);
        self::assertEqualsWithDelta(900, $hours['amounts']['kimai:994404'], 0.00001);

        $entries = $this->preview('work-entries', $receipts);
        self::assertEqualsWithDelta(600, $entries['amounts']['kimai:994403'], 0.00001);
        self::assertEqualsWithDelta(600, $entries['amounts']['kimai:994404'], 0.00001);
        self::assertSame($hours['eligible_total'], $entries['eligible_total']);
    }

    public function testCompetenceExcludesLaterEntriesAndReimportChangesResultDeterministically(): void
    {
        $reader = static::getContainer()->get(WorkMetricsReader::class);
        $work = $reader->read(Competencia::fromString('2026-07'));
        self::assertSame([994405, 994406, 994408], array_map(intval(...), array_column($work, 'id')));

        $approved = [['customer_reference' => 'CLIENTE-TESTE', 'eligible_amount' => 100, 'currency' => 'BRL']];
        $original = $this->preview('elapsed-hours', $approved);
        self::assertEqualsWithDelta(25, $original['amounts']['kimai:994403'], 0.00001);

        $this->connection->executeStatement('UPDATE timesheet SET duration = 7200 WHERE id = 994405');
        $afterChange = $this->preview('elapsed-hours', $approved);
        self::assertEqualsWithDelta(40, $afterChange['amounts']['kimai:994403'], 0.00001);
        self::assertEqualsWithDelta(60, $afterChange['amounts']['kimai:994404'], 0.00001);

        $this->connection->executeStatement('DELETE FROM timesheet WHERE id = 994406');
        $afterDeletion = $this->preview('elapsed-hours', $approved);
        self::assertEqualsWithDelta(100, $afterDeletion['amounts']['kimai:994403'], 0.00001);
        self::assertArrayNotHasKey('kimai:994404', $afterDeletion['amounts']);
    }

    public function testUnfundedClientCannotReceiveImplicitAmountFromBankBalances(): void
    {
        $tester = new CommandTester(static::getContainer()->get(AllocationPreviewCommand::class));
        $status = $tester->execute([
            '--ano-mes' => '2026-07',
            '--receipts-file' => $this->receiptFile([
                ['customer_reference' => 'SEM-PRODUCAO', 'eligible_amount' => 120, 'currency' => 'BRL'],
            ]),
            '--metric' => 'elapsed-hours',
        ]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('não há horas ponderadas', $tester->getDisplay());
    }

    public function testNonLibreCodeProfileCannotUseExistingFinancialIssuance(): void
    {
        $tester = new CommandTester(static::getContainer()->get(MakeProducaoCommand::class));
        $status = $tester->execute(['--ano-mes' => '2026-07', '--baixar-dados' => '0', '--atualiza-producao' => true]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('política de emissão', $tester->getDisplay());
    }

    public function testAmountsRequireExplicitCurrencyAndNonNegativeValues(): void
    {
        $tester = new CommandTester(static::getContainer()->get(AllocationPreviewCommand::class));
        $status = $tester->execute([
            '--ano-mes' => '2026-07',
            '--receipts-file' => $this->receiptFile([
                ['customer_reference' => 'CLIENTE-TESTE', 'eligible_amount' => -1, 'currency' => 'BRL'],
            ]),
        ]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('valores não negativos', $tester->getDisplay());
    }
}
