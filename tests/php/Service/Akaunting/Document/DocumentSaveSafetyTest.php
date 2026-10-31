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
use PHPUnit\Framework\Attributes\DataProvider;
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
            $request->expects($this->exactly(4))->method('send')->willReturnCallback(
                static function (string $endpoint, array $body = [], array $query = [], string $method = 'POST') use (&$calls): array {
                    $calls[] = [$endpoint, $method];
                    return match (count($calls)) {
                        1 => ['errors' => ['document_number' => ['taken']]],
                        2 => ['data' => [[
                            'id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'draft',
                            'company_id' => 1, 'type' => 'bill', 'contact_id' => 123,
                        ]]],
                        3 => ['data' => [
                            'id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'draft',
                            'company_id' => 1, 'type' => 'bill', 'contact_id' => 123,
                        ]],
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
                ['/api/documents/48', 'GET'],
                ['/api/documents/48', 'PATCH'],
            ], $calls);
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    #[DataProvider('unsafePreUpdateDocuments')]
    public function testPreviouslyStagedIdIsRevalidatedBeforeAnyPatch(
        array $replacement,
        string $expectedError
    ): void {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $existing = array_replace([
                'id' => 48,
                'document_number' => 'PDC-Teste',
                'status' => 'draft',
                'company_id' => 1,
                'type' => 'bill',
                'contact_id' => 123,
            ], $replacement);
            $request = $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->once())->method('send')
                ->with('/api/documents/48', [], ['search' => 'type:bill'], 'GET')
                ->willReturn(['data' => $existing]);
            try {
                $this->document($request)->setId(48)->save();
                self::fail('Remote state must block the update.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($expectedError, $error->getMessage());
                self::assertStringNotContainsString('PDC-Teste', $error->getMessage());
            }
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unsafePreUpdateDocuments(): array
    {
        return [
            'paid in Akaunting' => [['status' => 'paid'], 'finalizado'],
            'cancelled in Akaunting' => [['status' => 'cancelled'], 'finalizado'],
            'different company' => [['company_id' => 9], 'empresa, tipo ou contato'],
            'different document type' => [['type' => 'invoice'], 'empresa, tipo ou contato'],
            'different contact' => [['contact_id' => 456], 'empresa, tipo ou contato'],
            'different number' => [['document_number' => 'OUTRO'], 'Identificação'],
            'different API record ID' => [['id' => 49], 'Identificação'],
            'unknown status' => [['status' => 'overdue'], 'situação não habilitada'],
        ];
    }

    public function testValidStagedDraftIsReadBackBeforePatch(): void
    {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $calls = [];
            $request = $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->exactly(2))->method('send')->willReturnCallback(
                static function (
                    string $endpoint,
                    array $body = [],
                    array $query = [],
                    string $method = 'POST'
                ) use (&$calls): array {
                    $calls[] = [$endpoint, $method];
                    if ($method === 'GET') {
                        self::assertSame([], $body);
                        self::assertSame(['search' => 'type:bill'], $query);
                        return ['data' => [
                            'id' => 48,
                            'document_number' => 'PDC-Teste',
                            'company_id' => 1,
                            'type' => 'bill',
                            'contact_id' => 123,
                            'status' => 'draft',
                        ]];
                    }
                    self::assertSame('PATCH', $method);
                    throw new RuntimeException('PATCH_SENTINEL');
                }
            );
            try {
                $this->document($request)->setId(48)->save();
                self::fail('Expected to reach patch after exact document readback.');
            } catch (RuntimeException $error) {
                self::assertSame('PATCH_SENTINEL', $error->getMessage());
            }
            self::assertSame([
                ['/api/documents/48', 'GET'],
                ['/api/documents/48', 'PATCH'],
            ], $calls);
        } finally {
            putenv($old === false ? 'AKAUNTING_COMPANY_ID' : 'AKAUNTING_COMPANY_ID=' . $old);
        }
    }

    public function testDuplicateNumberSearchMustFindOneUniqueRecord(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
        $request->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            ['errors' => ['document_number' => ['taken']]],
            ['data' => [
                ['id' => 48, 'document_number' => 'PDC-Teste', 'status' => 'draft'],
                ['id' => 49, 'document_number' => 'PDC-Teste', 'status' => 'draft'],
            ]],
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registro exato');
        $this->document($request)->save();
    }

    public function testSourceReadRejectsRemoteIdentityMismatchBeforeImportingIt(): void
    {
        $old = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        try {
            $request = $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()->onlyMethods(['send', 'handleError'])->getMock();
            $request->expects($this->once())->method('send')->with(
                '/api/documents/48',
                [],
                ['search' => 'type:bill'],
                'GET'
            )->willReturn(['data' => [
                'id' => 48, 'document_number' => 'OUTRO', 'company_id' => 1,
                'type' => 'bill', 'contact_id' => 123, 'status' => 'draft',
            ]]);
            $documents = $this->createMock(Documents::class);
            $documents->expects($this->never())->method('fromArray');
            $doc = $this->document($request, $documents)->setId(48);
            $load = new \ReflectionMethod(ADocument::class, 'loadFromAkaunting');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Identificação');
            $load->invoke($doc);
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

    private function document(Request $request, ?Documents $documents = null): ADocument
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $dates = $this->createStub(Dates::class);
        $documents ??= $this->createStub(Documents::class);

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
