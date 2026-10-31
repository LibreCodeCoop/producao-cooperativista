<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Source;

use App\Helper\Dates;
use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use App\Service\Akaunting\Source\Documents;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentsEmptyListTest extends TestCase
{
    public function testNoDocumentsReturnsEmptyListWithoutUndefinedIndexNotice(): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())->method('list')->willReturn([]);
        $dates = new Dates('br-national', 12, new DateTime('2026-10-01'));
        $documents = new Documents(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->createStub(ParseText::class),
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
