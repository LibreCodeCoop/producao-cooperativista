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

    public function testConflictWithUnrelatedContactIsNotPatched(): void
    {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
                ['errors' => ['document_number' => ['taken']]],
                ['data' => [[
                    'id' => 48,
                    'document_number' => 'PDC-Teste',
                    'status' => 'draft',
                    'company_id' => 1,
                    'type' => 'bill',
                    'contact_id' => 999,
                ]]]
            );
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('tipo ou contato');
            $this->document($request)->save();
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    public function testConflictWithUnknownStatusCannotBePatched(): void
    {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
                ['errors' => ['document_number' => ['taken']]],
                ['data' => [[
                    'id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'unknown',
                    'company_id' => 1, 'type' => 'bill', 'contact_id' => 123,
                ]]]
            );
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('situação não habilitada');
            $this->document($request)->save();
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    public function testMatchingUnpaidDocumentCanReachPatchWithoutCreatingAnother(): void
    {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $calls = [];
            $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->exactly(3))->method('send')->willReturnCallback(
                static function (string $endpoint, array $body = [], array $query = [], string $method = 'POST') use (&$calls): array {
                    $calls[] = [$endpoint, $method];
                    return match (count($calls)) {
                        1 => ['errors' => ['document_number' => ['taken']]],
                        2 => ['data' => [[
                            'id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'draft',
                            'company_id' => 1, 'type' => 'bill', 'contact_id' => 123,
                        ]]],
                        default => throw new RuntimeException('PATCH_SENTINEL'),
                    };
                }
            );
            try {
                $this->document($request)->save();
                self::fail('The control must reach the PATCH invocation.');
            } catch (RuntimeException $error) {
                self::assertSame('PATCH_SENTINEL', $error->getMessage());
            }
            self::assertSame([
                ['/api/documents', 'POST'],
                ['/api/documents', 'GET'],
                ['/api/documents/48', 'PATCH'],
            ], $calls);
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    public function testEmptyCreateResponseDoesNotClaimSuccess(): void
    {
        $request = $this->createStub(Request::class);
        $request->method('send')->willReturn(['message' => 'ok']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não retornou os dados');
        $this->document($request)->save();
    }

    private function document(Request $request): ADocument
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $dates = $this->createStub(Dates::class);
        $documents = $this->createStub(Documents::class);

        return new class ($manager, $dates, $documents, $request, new DocumentConfiguration()) extends ADocument {
            protected function setUp(): self
            {
                $this->action = self::ACTION_CREATE;
                $this->setType('bill');
                $this->setContactId(123);
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
