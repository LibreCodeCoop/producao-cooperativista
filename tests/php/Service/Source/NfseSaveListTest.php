<?php

declare(strict_types=1);

namespace App\Tests\Service\Source;

use App\Entity\Producao\Nfse as NfseEntity;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use App\Service\Source\Nfse;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NfseSaveListTest extends TestCase
{
    public function testImportedNfsePersistsOnceWithTypedNumberAndArrayMetadata(): void
    {
        $repo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()->getMock();
        $repo->expects($this->once())->method('findBy')->willReturn([]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repo);
        $manager->expects($this->once())->method('persist')
            ->willReturnCallback(static function (NfseEntity $nfse): void {
                self::assertSame(42, $nfse->getNumero());
                self::assertSame('00111222000199', $nfse->getCnpj());
                self::assertSame(1234.50, $nfse->getValorServico());
                self::assertNull($nfse->getNumeroSubstituta());
                self::assertSame('42', $nfse->getMetadata()['Nº da Nota Fiscal Eletrônica']);
            });
        $manager->expects($this->once())->method('flush');

        $this->source($manager)->saveList([$this->row()]);
    }

    public function testDuplicateMunicipalInvoiceNumbersFailBeforeDatabaseChanges(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('duplicados');
        $row = $this->row();
        $this->source($manager)->saveList([$row, $row]);
    }

    private function source(EntityManagerInterface $manager): Nfse
    {
        $source = new Nfse($manager, new NullLogger(), new ExistingEntityIndex(), new BatchPersistence());
        (new \ReflectionProperty(Nfse::class, 'prefeitura'))->setValue($source, 'niteroi');
        return $source;
    }

    private function row(): array
    {
        return [
            'Nº da Nota Fiscal Eletrônica' => '42',
            'CPF/CNPJ/NIF do Tomador' => '00111222000199',
            'Razão Social do Tomador' => 'Cliente teste',
            'Data Hora da Emissão da Nota Fiscal' => '10/09/2026 12:00:00',
            'Valor dos Serviços' => '1.234,50',
            'Valor COFINS' => '0,00',
            'Valor IR' => '0,00',
            'Valor PIS/PASEP' => '0,00',
            'Valor do ISS' => '24,69',
            'Nº Nota Fiscal Substituta' => null,
            'discriminacao_normalizada' => 'Serviço prestado',
            'setor' => null,
            'codigo_cliente' => '00111222000199',
        ];
    }
}
