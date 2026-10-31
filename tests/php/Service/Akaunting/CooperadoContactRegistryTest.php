<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting;

use App\Entity\Akaunting\Contacts;
use App\Service\Akaunting\CooperadoContactRegistry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CooperadoContactRegistryTest extends TestCase
{
    public function testReusesExistingContactOnRerun(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(73);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($conn);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->never())->method('flush');

        self::assertSame(73, new CooperadoContactRegistry($manager)->getOrCreate(
            'Cooperado',
            '00011122233',
            1,
            new DateTimeImmutable('2026-10-01')
        ));
    }

    public function testMissingContactIsPersistedOnceWithCorrectTypes(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($conn);
        $manager->expects($this->once())->method('persist')->willReturnCallback(function (Contacts $contact) {
            self::assertSame('00011122233', $contact->getTaxNumber());
            self::assertSame(1, $contact->getCompanyId());
            $contact->setId(74);
        });
        $manager->expects($this->once())->method('flush');

        self::assertSame(74, new CooperadoContactRegistry($manager)->getOrCreate(
            'Cooperado',
            '00011122233',
            1,
            new DateTimeImmutable('2026-10-01')
        ));
    }
}
