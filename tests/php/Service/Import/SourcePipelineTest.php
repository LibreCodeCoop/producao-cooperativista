<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Helper\Dates;
use App\Service\Import\SourcePipeline;
use App\Service\Import\SourceStep;
use ArrayObject;
use DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SourcePipelineTest extends TestCase
{
    public function testStepsReceiveOneNormalizedCompetenceAndHaveIsolatedDates(): void
    {
        $events = new ArrayObject();
        $first = $this->step('primeira', $events, true);
        $second = $this->step('segunda', $events);
        $dates = new Dates(now: new DateTime('2026-10-20'));
        $pipeline = new SourcePipeline([$first, $second], new NullLogger(), $dates);

        $input = new DateTime('2025-11-25 13:45:00');
        $pipeline->run($input);
        self::assertSame('2025-11-25 13:45:00', $input->format('Y-m-d H:i:s'));
        self::assertSame('2025-11', $dates->getInicio()->format('Y-m'));
        self::assertSame(['primeira:2025-11-01', 'segunda:2025-11-01'], $events->getArrayCopy());
    }

    public function testStagesEmitSafeProgressEventsWithOrdinalAndTotal(): void
    {
        $events = new ArrayObject();
        $log = new \Monolog\Logger('app');
        $handler = new \Monolog\Handler\TestHandler();
        $log->pushHandler($handler);
        $pipeline = new SourcePipeline([
            $this->step('kimai.customers', $events),
            $this->step('kimai.users', $events),
        ], $log, new Dates(now: new DateTime('2026-11-01')));
        $pipeline->run(new DateTime('2026-09-01'));
        $progress = [];
        foreach ($handler->getRecords() as $record) {
            $value = json_decode((string) $record->message, true);
            if (is_array($value) && ($value['event'] ?? '') === 'info') {
                $progress[] = $value['data'];
            }
        }
        self::assertSame([
            'Etapa 1 de 2: Clientes do Kimai.',
            'Etapa 2 de 2: Cooperados do Kimai e perfis Nextcloud.',
        ], $progress);
    }

    public function testFailingConnectorAbortsLaterStagesWithoutWritingFinancialDocuments(): void
    {
        $events = new ArrayObject();
        $pipeline = new SourcePipeline([
            $this->step('kimai', $events),
            new class ($events) implements SourceStep {
                public function __construct(private ArrayObject $events)
                {
                }
                public function name(): string
                {
                    return 'akaunting';
                }
                public function synchronize(DateTime $competence): void
                {
                    $this->events->append('failure:' . $competence->format('Y-m'));
                    throw new RuntimeException('Falha de origem');
                }
            },
            $this->step('never-executed', $events),
        ], new NullLogger(), new Dates(now: new DateTime('2026-10-20')));

        try {
            $pipeline->run(new DateTime('2024-02-11'));
            self::fail('An unsuccessful source must fail the entire ETL stage.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('2024-02', $error->getMessage());
            self::assertStringContainsString('akaunting', $error->getMessage());
            self::assertSame('Falha de origem', $error->getPrevious()?->getMessage());
            self::assertSame(['kimai:2024-02-01', 'failure:2024-02'], $events->getArrayCopy());
        }
    }

    public function testDependentValidationKeepsOnlyAllowedDiagnosticMetadata(): void
    {
        $events = new ArrayObject();
        $pipeline = new SourcePipeline([
            new class ($events) implements SourceStep {
                public function __construct(private ArrayObject $events)
                {
                }
                public function name(): string
                {
                    return 'kimai.users';
                }
                public function synchronize(DateTime $competence): void
                {
                    $this->events->append('kimai.users');
                    throw new \DomainException(
                        'PROFILE_DEPENDENTS: usuário Kimai #12 sem dependentes (CPF never-stream-this)'
                    );
                }
            },
            $this->step('not-run', $events),
        ], new NullLogger(), new Dates(now: new DateTime('2026-11-01')));

        try {
            $pipeline->run(new DateTime('2026-09-01'));
            self::fail('Invalid dependents must block import.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('kimai.users', $error->getMessage());
            self::assertStringContainsString('Kimai #12', $error->getMessage());
            self::assertSame(['kimai.users'], $events->getArrayCopy());
        }
    }

    public function testDuplicatedStageNamesAreRejected(): void
    {
        $events = new ArrayObject();
        $this->expectException(\InvalidArgumentException::class);
        new SourcePipeline([
            $this->step('kimai', $events),
            $this->step('kimai', $events),
        ], new NullLogger(), new Dates());
    }

    private function step(string $name, ArrayObject $events, bool $mutateDate = false): SourceStep
    {
        return new class ($name, $events, $mutateDate) implements SourceStep {
            public function __construct(
                private string $id,
                private ArrayObject $events,
                private bool $mutateDate,
            ) {
            }

            public function name(): string
            {
                return $this->id;
            }

            public function synchronize(DateTime $competence): void
            {
                $this->events->append($this->id . ':' . $competence->format('Y-m-d'));
                if ($this->mutateDate) {
                    $competence->modify('+1 year');
                }
            }
        };
    }
}
