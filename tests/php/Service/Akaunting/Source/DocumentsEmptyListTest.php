<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Source;

use App\Helper\Dates;
use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\FieldStudio;
use App\Service\Akaunting\Source\Documents;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentsEmptyListTest extends TestCase
{
    public function testUnassignedSupplierBillDoesNotInheritSupplierOrCompanyTaxNumber(): void
    {
        $documents = new Documents(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->createStub(FieldStudio::class),
            $this->createStub(Dataset::class),
            new Dates('br-national', 12, new DateTime('2026-10-01')),
            new BatchPersistence(),
            new ExistingEntityIndex(),
        );
        $method = new \ReflectionMethod($documents, 'defineCustomerReference');
        $bill = ['type' => 'bill', 'contact' => [
            'type' => 'vendor', 'reference' => '99999999000199',
            'tax_number' => '99999999000199',
        ]];
        self::assertSame('', $method->invoke($documents, $bill)['customer_reference']);

        $bill['customer'] = '00000000000191';
        self::assertSame('00000000000191', $method->invoke($documents, $bill)['customer_reference']);

        $invoice = ['type' => 'invoice', 'contact' => [
            'type' => 'customer', 'reference' => '00000000000191',
        ]];
        self::assertSame('00000000000191', $method->invoke($documents, $invoice)['customer_reference']);

        $invoice['contact']['type'] = 'vendor';
        self::assertSame('', $method->invoke($documents, $invoice)['customer_reference']);
    }

    public function testNoDocumentsReturnsEmptyListWithoutUndefinedIndexNotice(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn([]);
        $dates = new Dates('br-national', 12, new DateTime('2026-10-01'));
        $documents = new Documents(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->createStub(FieldStudio::class),
            $dataset,
            $dates,
            new BatchPersistence(),
            new ExistingEntityIndex(),
        );
        $documents->setDate(new DateTime('2026-10-01'));
        self::assertSame([], $documents->getList());
        self::assertSame([], $documents->getList());
    }
}
