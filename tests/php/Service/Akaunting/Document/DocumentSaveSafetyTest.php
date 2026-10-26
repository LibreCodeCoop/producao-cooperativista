<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Document;

use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\ADocument;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use App\Helper\Dates;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentSaveSafetyTest extends TestCase
{
    public function testDuplicateDocumentDoesNotUpdateUnrelatedSearchResult(): void
    {
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
        $request->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            ['errors' => ['document_number' => ['taken']]],
            ['data' => [['id' => 48, 'document_number' => 'OTHER', 'status' => 'draft']]]
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registro exato');

        $this->document($request)->save();
    }

    public function testDuplicatePaidDocumentIsNotOverwritten(): void
    {
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
        $request->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            ['errors' => ['document_number' => ['taken']]],
            ['data' => [['id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'paid']]]
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('finalizado');

        $this->document($request)->save();
    }

    public function testEmptyCreateResponseDoesNotClaimSuccess(): void
    {
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
        $request->method('send')->willReturn(['message' => 'ok']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não retornou os dados');
        $this->document($request)->save();
    }

    private function document(Request $request): ADocument
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $dates = $this->getMockBuilder(Dates::class)->disableOriginalConstructor()->getMock();
        $documents = $this->getMockBuilder(Documents::class)->disableOriginalConstructor()->getMock();

        return new class ($manager, $dates, $documents, $request, new DocumentConfiguration()) extends ADocument {
            protected function setUp(): self
            {
                $this->action = self::ACTION_CREATE;
                return $this;
            }

            protected function getDocumentNumber(): string
            {
                return 'PDC-Teste';
            }

            public function toArray(): array
            {
                return ['document_number' => 'PDC-Teste'];
            }
        };
    }
}
