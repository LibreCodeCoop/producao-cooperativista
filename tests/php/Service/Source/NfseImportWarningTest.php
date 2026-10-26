<?php

declare(strict_types=1);

namespace App\Tests\Service\Source;

use App\Service\Source\Nfse;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NfseImportWarningTest extends TestCase
{
    public function testMissingCredentialsProduceAnActionableWarningWithoutDatabaseWrites(): void
    {
        foreach (['PREFEITURA', 'PREFEITURA_LOGIN', 'PREFEITURA_SENHA'] as $key) {
            putenv($key);
        }
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->never())->method('flush');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with(self::stringContains('PREFEITURA_LOGIN'));

        (new Nfse(
            $manager,
            $logger,
            new \App\Service\Import\ExistingEntityIndex(),
            new \App\Service\Import\BatchPersistence(),
        ))->updateDatabase(new DateTime('2026-09-01'));
    }
}
