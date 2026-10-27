<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Source;

use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use App\Service\Akaunting\Source\Transactions;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use App\Service\Import\AssociatedDocumentResolver;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TransactionsDateRangeTest extends TestCase
{
    public function testPastMonthUsesYearAwareRangeThroughCurrentMonth(): void
    {
        $selected = new DateTime('first day of -1 year');
        $expectedFrom = $selected->format('Y-m-01');
        $expectedTo = new DateTime('last day of this month')->format('Y-m-d');
        $original = $selected->format('Y-m-d');

        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')
            ->willReturnCallback(static function (string $endpoint, array $query) use ($expectedFrom, $expectedTo): array {
                self::assertSame('/api/transactions', $endpoint);
                self::assertStringContainsString('paid_at>=' . $expectedFrom, $query['search']);
                self::assertStringContainsString('paid_at<=' . $expectedTo, $query['search']);
                return [];
            });
        $source = $this->source($dataset);
        $source->setDate($selected);
        self::assertSame([], $source->getList());
        self::assertSame($original, $selected->format('Y-m-d'));
    }

    public function testFutureMonthNeverFetchesPastCurrentMonth(): void
    {
        $selected = new DateTime('first day of +1 year');
        $expectedTo = (clone $selected)->modify('last day of this month')->format('Y-m-d');
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturnCallback(
            static function (string $endpoint, array $query) use ($expectedTo): array {
                self::assertStringContainsString('paid_at<=' . $expectedTo, $query['search']);
                return [];
            }
        );

        $source = $this->source($dataset);
        $source->setDate($selected);
        self::assertSame([], $source->getList());
    }

    public function testTwoPaymentsForOneHistoricalDocumentUseOneBatchAndCorrectMonth(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->exactly(2))->method('list')->willReturnCallback(
            static function (string $endpoint, array $query): array {
                if ($endpoint === '/api/transactions') {
                    return array_map(static fn (int $id): array => [
                        'id' => $id,
                        'document_id' => 73,
                        'paid_at' => '2026-05-12T09:00:00-03:00',
                        'amount' => 50.0,
                        'type' => 'income',
                        'currency_code' => 'BRL',
                        'reference' => '',
                        'contact' => [
                            'id' => 9,
                            'reference' => null,
                            'name' => 'Cliente',
                            'type' => 'customer',
                            'tax_number' => '12345678000190',
                        ],
                        'category_id' => 20,
                        'category' => ['name' => 'Serviço', 'type' => 'income'],
                        'customer_reference' => null,
                        'description' => '',
                        'archive' => 'não',
                        'discount_percentage' => null,
                        'nfse' => null,
                    ], [11, 12]);
                }
                self::assertSame('/api/documents', $endpoint);
                self::assertSame('type:invoice id:73', $query['search']);
                return [[
                    'id' => 73,
                    'type' => 'invoice',
                    'company_id' => 1,
                    'notes' => "Transação do mês: 2026-04\nCNPJ cliente: 12345678000190",
                    'contact' => ['reference' => null, 'tax_number' => '12345678000190'],
                ]];
            }
        );

        $repository = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()->getMock();
        $repository->expects($this->once())->method('findBy')->willReturn([]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('getRepository')->willReturn($repository);

        $source = new Transactions(
            $manager,
            new NullLogger(),
            new ParseText(),
            $dataset,
            new BatchPersistence(),
            new ExistingEntityIndex(),
            new AssociatedDocumentResolver($dataset, new ParseText()),
        );
        $source->setDate(new DateTime('2026-05-01'));
        $payments = $source->getList();
        self::assertCount(2, $payments);
        foreach ($payments as $payment) {
            self::assertSame('12345678000190', $payment->getCustomerReference());
            self::assertSame('2026-04', $payment->getTransactionOfMonth());
        }
    }

    private function source(Dataset $dataset): Transactions
    {
        return new Transactions(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->createStub(ParseText::class),
            $dataset,
            new BatchPersistence(),
            new ExistingEntityIndex(),
            new AssociatedDocumentResolver($dataset, new ParseText()),
        );
    }
}
