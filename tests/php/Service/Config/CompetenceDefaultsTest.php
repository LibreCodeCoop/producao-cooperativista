<?php

declare(strict_types=1);

namespace App\Tests\Service\Config;

use App\Service\Config\CompetenceDefaults;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class CompetenceDefaultsTest extends TestCase
{
    private string|false $originalLag;

    protected function setUp(): void
    {
        $this->originalLag = getenv('PRODUCAO_FECHAMENTO_DEFASAGEM_MESES');
        putenv('PRODUCAO_FECHAMENTO_DEFASAGEM_MESES=2');
    }

    protected function tearDown(): void
    {
        putenv($this->originalLag === false
            ? 'PRODUCAO_FECHAMENTO_DEFASAGEM_MESES'
            : 'PRODUCAO_FECHAMENTO_DEFASAGEM_MESES=' . $this->originalLag);
    }

    public function testClosingMonthIsTwoMonthsAfterWorkAcrossYearBoundaries(): void
    {
        self::assertSame('2026-08', CompetenceDefaults::workMonth(new DateTimeImmutable('2026-10-31')));
        self::assertSame('2026-09', CompetenceDefaults::workMonth(new DateTimeImmutable('2026-11-01')));
        self::assertSame('2025-11', CompetenceDefaults::workMonth(new DateTimeImmutable('2026-01-01')));
        self::assertSame('2024-12', CompetenceDefaults::workMonth(new DateTimeImmutable('2025-02-28')));
    }

    public function testClosingLagIsConfiguredWithoutHardCodingCooperativePolicy(): void
    {
        putenv('PRODUCAO_FECHAMENTO_DEFASAGEM_MESES=3');
        self::assertSame('2026-07', CompetenceDefaults::workMonth(new DateTimeImmutable('2026-10-01')));
        putenv('PRODUCAO_FECHAMENTO_DEFASAGEM_MESES=0');
        $this->expectException(\DomainException::class);
        CompetenceDefaults::closingLag();
    }

    public function testReportsChooseLastActualImportedMonth(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')
            ->willReturn('2026-08-31 14:00:00');
        $defaults = new CompetenceDefaults($db);
        self::assertSame('2026-08', $defaults->importedMonth());
        self::assertSame('2026-08', $defaults->reportMonth());
    }

    public function testEmptyImportUsesOperationalDefault(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(null);
        self::assertSame(CompetenceDefaults::workMonth(), new CompetenceDefaults($db)->reportMonth());
    }
}
