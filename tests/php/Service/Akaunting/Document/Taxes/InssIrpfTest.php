<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 *
 * @author LibreCode contributors
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Document\Taxes;

use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\ADocument;
use App\Service\Akaunting\Document\Taxes\InssIrpf;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use App\Service\Cooperado;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
use Tests\Php\TestCase;

final class InssIrpfTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private Dates $dates;
    private Documents $documents;
    private Request $request;
    private DocumentConfiguration $documentConfiguration;
    private NumberFormatter $numberFormatter;

    protected function setUp(): void
    {
        static::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->dates = $container->get(Dates::class);
        $this->documents = $container->get(Documents::class);
        $this->request = $container->get(Request::class);
        $this->documentConfiguration = $container->get(DocumentConfiguration::class);
        $this->numberFormatter = new NumberFormatter((string) getenv('LOCALE'), NumberFormatter::CURRENCY);

        $this->dates->setInicio(new DateTime('2026-04-01 00:00:00'));
    }

    public function testSaveFromDocumentDeduplicatesCurrentCooperadoTaxesAndOrdersCooperadosAlphabetically(): void
    {
        $ana = $this->createCooperado('Ana Clara', '00011122233');
        $bruno = $this->createCooperado('Bruno Souza', '00011122244');

        $document = $this->createInssIrpfDocument($bruno);
        $document
            ->setItem(code: 'INSS', name: 'INSS ' . $bruno->getName(), description: 'Documento: guia-antiga', price: 10.0)
            ->setItem(code: 'IRRF', name: 'IRRF ' . $bruno->getName(), description: 'Documento: guia-antiga', price: 1.0)
            ->setItem(code: 'IRRF', name: 'IRRF ' . $ana->getName(), description: 'Documento: PDC_00011122233-2026-05', price: 5.0)
            ->setItem(code: 'INSS', name: 'INSS ' . $ana->getName(), description: 'Documento: PDC_00011122233-2026-05', price: 20.0);

        $this->assertSame(
            [$bruno->getName(), $bruno->getName(), $ana->getName(), $ana->getName()],
            $this->extractCooperadoNames($document->getItems()),
            'Pré-condição inválida: o teste precisa começar fora de ordem alfabética para provar a ordenação.'
        );

        $sourceDocument = $this->createSourceDocument('PDC_00011122244-2026-05')
            ->addTaxItem('IRRF', 8.5)
            ->addTaxItem('INSS', 99.9);

        $document->saveFromDocument($sourceDocument);

        $items = $document->getItems();

        $this->assertSame(
            [$ana->getName(), $ana->getName(), $bruno->getName(), $bruno->getName()],
            $this->extractCooperadoNames($items),
            'Os itens da guia devem ficar em ordem alfabética pelo nome do cooperado.'
        );

        $this->assertSame(
            ['INSS ' . $ana->getName(), 'IRRF ' . $ana->getName(), 'INSS ' . $bruno->getName(), 'IRRF ' . $bruno->getName()],
            array_column($items, 'name')
        );
        $this->assertCount(1, array_filter($items, fn (array $item): bool => $item['name'] === 'INSS ' . $bruno->getName()));
        $this->assertCount(1, array_filter($items, fn (array $item): bool => $item['name'] === 'IRRF ' . $bruno->getName()));
        $this->assertSame('Documento: PDC_00011122244-2026-05', $items[2]['description']);
        $this->assertSame('Documento: PDC_00011122244-2026-05', $items[3]['description']);
        $this->assertSame(99.9, $items[2]['price']);
        $this->assertSame(8.5, $items[3]['price']);
    }

    public function testSaveFromDocumentRemovesObsoleteCurrentCooperadoTaxItemsWhenSourceDoesNotContainThem(): void
    {
        $bruno = $this->createCooperado('Bruno Souza', '00011122244');

        $document = $this->createInssIrpfDocument($bruno);
        $document
            ->setItem(code: 'INSS', name: 'INSS Bruno Souza', description: 'Documento: guia-antiga', price: 10.0)
            ->setItem(code: 'IRRF', name: 'IRRF Bruno Souza', description: 'Documento: guia-antiga', price: 1.0);

        $sourceDocument = $this->createSourceDocument('PDC_00011122244-2026-05')
            ->addTaxItem('INSS', 42.0);

        $document->saveFromDocument($sourceDocument);

        $items = $document->getItems();

        $this->assertSame(['INSS Bruno Souza'], array_column($items, 'name'));
        $this->assertSame('Documento: PDC_00011122244-2026-05', $items[0]['description']);
        $this->assertSame(42.0, $items[0]['price']);
    }

    private function createCooperado(string $name, string $taxNumber): Cooperado
    {
        $cooperado = new Cooperado(
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
            anoFiscal: 2026,
            mes: 4,
        );

        $cooperado
            ->setName($name)
            ->setTaxNumber($taxNumber)
            ->setAkauntingContactId(172)
            ->setDependentes(0)
            ->setWeight(1);

        return $cooperado;
    }

    private function createInssIrpfDocument(Cooperado $cooperado): InspectableInssIrpf
    {
        return new InspectableInssIrpf(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
            anoFiscal: 2026,
            mes: 4,
            numberFormatter: $this->numberFormatter,
            cooperado: $cooperado,
        );
    }

    private function createSourceDocument(string $documentNumber): SourceTaxDocument
    {
        $document = new SourceTaxDocument(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
            anoFiscal: 2026,
            mes: 4,
            numberFormatter: $this->numberFormatter,
        );

        $document->setDocumentNumber($documentNumber);

        return $document;
    }

    /**
     * @param array<int, array{name: string}> $items
     * @return string[]
     */
    private function extractCooperadoNames(array $items): array
    {
        return array_map(
            fn (array $item): string => preg_replace('/^(INSS|IRRF)\s+/u', '', $item['name']) ?? $item['name'],
            $items
        );
    }
}

final class InspectableInssIrpf extends InssIrpf
{
    protected function coletaInvoiceNaoPago(): self
    {
        return $this;
    }

    public function save(): self
    {
        return $this;
    }
}

final class SourceTaxDocument extends ADocument
{
    protected function setUp(): self
    {
        return $this;
    }

    public function addTaxItem(string $code, float $price): self
    {
        return $this->setItem(code: $code, name: $code, price: $price);
    }
}
