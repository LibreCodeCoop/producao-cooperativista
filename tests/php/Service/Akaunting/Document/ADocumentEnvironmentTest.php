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

namespace App\Tests\Service\Akaunting\Document;

use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Document\ADocument;
use App\Service\Akaunting\Document\Taxes\Tax;
use App\Service\Akaunting\Source\Documents;
use Doctrine\ORM\EntityManagerInterface;
use Tests\Php\TestCase;

final class ADocumentEnvironmentTest extends TestCase
{
    private const ITEM_IDS_JSON = '{"bruto":26,"INSS":28,"IRRF":29,"Auxílio":32,"desconto":31,"frra":27}';

    private EntityManagerInterface $entityManager;
    private Dates $dates;
    private Documents $documents;
    private Request $request;
    private DocumentConfiguration $documentConfiguration;

    protected function setUp(): void
    {
        static::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->dates = $container->get(Dates::class);
        $this->documents = $container->get(Documents::class);
        $this->request = $container->get(Request::class);
        $this->documentConfiguration = $container->get(DocumentConfiguration::class);
    }

    public function testCreateDocumentSupportsSingleQuotedJsonItemIdsEnvironment(): void
    {
        $previousValue = getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS');
        putenv(sprintf(
            "AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS='%s'",
            self::ITEM_IDS_JSON
        ));

        try {
            $document = $this->createDocument();

            $this->assertSame(31, $document->getItemsIds()['desconto']);
        } finally {
            $this->restoreEnvironmentVariable('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS', $previousValue);
        }
    }

    public function testCreateDocumentRejectsInvalidJsonItemIdsEnvironmentWithHelpfulMessage(): void
    {
        $previousValue = getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS');
        putenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS={bruto:26}');

        try {
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS must contain valid JSON');

            $this->createDocument()->getItemsIds();
        } finally {
            $this->restoreEnvironmentVariable('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS', $previousValue);
        }
    }

    public function testCreateDocumentFailsWhenRequestedItemCodeDoesNotExist(): void
    {
        $previousValue = getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS');
        putenv("AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS='{" .
            '"desconto":31' .
            "}'");

        try {
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS must define item id for code "bruto"');

            $this->createDocument()->addItemForTest('bruto');
        } finally {
            $this->restoreEnvironmentVariable('AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS', $previousValue);
        }
    }

    public function testTaxSupportsSingleQuotedJsonEnvironment(): void
    {
        $previousValue = getenv('AKAUNTING_IMPOSTOS_TAX');
        putenv("AKAUNTING_IMPOSTOS_TAX='{" .
            '"categoryId":40,"contactId":111,"taxId":1' .
            "}'");

        try {
            $document = $this->createTaxDocument();
            $taxData = $document->getTaxDataForTest();

            $this->assertSame(40, $taxData['categoryId']);
            $this->assertSame(111, $taxData['contactId']);
            $this->assertSame(1, $taxData['taxId']);
        } finally {
            $this->restoreEnvironmentVariable('AKAUNTING_IMPOSTOS_TAX', $previousValue);
        }
    }

    public function testTaxFailsWhenRequestedFieldDoesNotExist(): void
    {
        $previousValue = getenv('AKAUNTING_IMPOSTOS_TAX');
        putenv("AKAUNTING_IMPOSTOS_TAX='{" .
            '"categoryId":40' .
            "}'");

        try {
            $document = $this->createTaxDocument();

            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('AKAUNTING_IMPOSTOS_TAX must define "taxId"');

            $document->getTaxDataValueForTest('taxId');
        } finally {
            $this->restoreEnvironmentVariable('AKAUNTING_IMPOSTOS_TAX', $previousValue);
        }
    }

    private function createDocument(): EnvironmentTestDocument
    {
        return new EnvironmentTestDocument(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
    }

    private function createTaxDocument(): EnvironmentTestTax
    {
        return new EnvironmentTestTax(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
    }

    private function restoreEnvironmentVariable(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}

final class EnvironmentTestDocument extends ADocument
{
    protected function setUp(): self
    {
        return $this;
    }

    public function addItemForTest(string $code): self
    {
        return $this->setItem(code: $code, name: 'Teste', price: 1.0);
    }
}

final class EnvironmentTestTax extends Tax
{
    protected function coletaInvoiceNaoPago(): self
    {
        return $this;
    }

    public function getTaxDataForTest(): array
    {
        return $this->getTaxData();
    }

    public function getTaxDataValueForTest(string $key): int
    {
        return $this->getTaxDataInt($key);
    }
}