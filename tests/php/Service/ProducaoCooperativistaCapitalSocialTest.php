<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProducaoCooperativista;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProducaoCooperativistaCapitalSocialTest extends TestCase
{
    public function testCapitalSocialExecutesCompleteSqlOnAccountingConnection(): void
    {
        $expected = [
            ['contact_id' => 12, 'name' => 'Cooperado', 'amount' => '100.00', 'table' => 'transaction'],
        ];
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($expected);

        $accounting = $this->createMock(Connection::class);
        $accounting->expects(self::once())->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'FROM transactions t')
                && str_contains($sql, 'JOIN documents as d')
                && substr_count($sql, 'AS `table`') === 3
                && str_contains($sql, 'ORDER BY x.name, x.due_at')))
            ->willReturn($result);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($accounting);

        $reflection = new ReflectionClass(ProducaoCooperativista::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('entityManagerAkaunting')->setValue($service, $manager);

        self::assertSame($expected, $service->getCapitalSocial());
    }
}
