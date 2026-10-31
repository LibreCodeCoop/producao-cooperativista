<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting;

use App\Provider\Akaunting\Request;
use App\Service\Akaunting\PublicationWriteJournal;
use App\Service\Import\StagingSchema;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PublicationWriteJournalTest extends KernelTestCase
{
    private Connection $connection;
    private PublicationWriteJournal $journal;
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine.dbal.main_connection');
        $this->connection->beginTransaction();
        $this->journal = new PublicationWriteJournal($this->connection);
        foreach ([
            'AKAUNTING_API_BASE_URL' => 'https://accounting.example.test',
            'AKAUNTING_AUTH_USER' => 'synthetic@example.test',
            'AKAUNTING_AUTH_TOKEN' => 'synthetic-token',
            'AKAUNTING_COMPANY_ID' => '1',
        ] as $name => $value) {
            $this->previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        foreach ($this->previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        parent::tearDown();
    }

    /** @return array<string, int|string> */
    private function document(): array
    {
        return [
            'type' => 'bill',
            'document_number' => 'PDC_SYNTHETIC_2026-08',
            'contact_id' => 123,
            'amount' => '0.10',
            'notes' => 'synthetic-not-a-real-document',
        ];
    }

    private function latest(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT state, attempt_number, remote_document_id, http_status, operation_key, '
            . 'document_fingerprint, payload_fingerprint '
            . 'FROM publication_write_attempts ORDER BY started_at DESC, attempt_number DESC LIMIT 1'
        );
        self::assertIsArray($row);
        return $row;
    }

    public function testSuccessfulDocumentWriteLeavesDurableConfirmation(): void
    {
        $body = $this->document();
        $http = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                'id' => 501, 'document_number' => $body['document_number'],
                'type' => $body['type'], 'contact_id' => $body['contact_id'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 201]));
        $request = new Request(new NullLogger(), $http, $this->journal);

        self::assertSame(501, $request->send('/api/documents', $body)['data']['id']);
        self::assertSame('confirmed', $this->latest()['state']);
        self::assertSame(501, (int) $this->latest()['remote_document_id']);
        self::assertSame(201, (int) $this->latest()['http_status']);
    }

    public function testLostWriteResponseBlocksAnyAutomaticReplay(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('simulated lost response');
        });
        $request = new Request(new NullLogger(), $http, $this->journal);
        try {
            $request->send('/api/documents', $this->document());
            self::fail('A lost response must be reported.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Falha de conexão', $error->getMessage());
        }
        self::assertSame('uncertain', $this->latest()['state']);
        try {
            $request->send('/api/documents', $this->document());
            self::fail('A second request must not reach the API.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('resultado incerto', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM publication_write_attempts'
        ));
    }

    #[DataProvider('ambiguousResponses')]
    public function testAmbiguousHttpResponsesCannotBeReplayed(int $status, string $json): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls, $status, $json): MockResponse {
            ++$calls;
            return new MockResponse($json, ['http_code' => $status]);
        });
        $request = new Request(new NullLogger(), $http, $this->journal);
        try {
            $request->send('/api/documents', $this->document());
            self::fail('Uncertain response must not be accepted as successful.');
        } catch (RuntimeException) {
        }

        self::assertSame('uncertain', $this->latest()['state']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resultado incerto');
        try {
            $request->send('/api/documents', $this->document());
        } finally {
            self::assertSame(1, $calls);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function ambiguousResponses(): array
    {
        return [
            'remote server failure' => [503, '{"message":"server error"}'],
            'request timeout' => [408, '{"message":"timeout"}'],
            'partial success payload' => [201, '{"data":{"id":501}}'],
            'success with wrong identity' => [201, '{"data":{"id":501,"document_number":"OTHER","type":"bill","contact_id":123}}'],
        ];
    }

    public function testInvalidJsonWriteResponseRemainsUncertain(): void
    {
        $http = new MockHttpClient(new MockResponse('invalid-json', ['http_code' => 200]));
        $request = new Request(new NullLogger(), $http, $this->journal);
        $this->expectException(RuntimeException::class);
        try {
            $request->send('/api/documents', $this->document());
        } finally {
            self::assertSame('uncertain', $this->latest()['state']);
        }
    }

    public function testPatchResponseMustMatchTargetId(): void
    {
        $document = $this->document();
        $document['id'] = 501;
        $http = new MockHttpClient(new MockResponse(json_encode(['data' => [
            'id' => 999,
            'type' => 'bill',
            'contact_id' => 123,
            'document_number' => $document['document_number'],
        ]], JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $request = new Request(new NullLogger(), $http, $this->journal);
        $this->expectException(RuntimeException::class);
        try {
            $request->send('/api/documents/501', $document, method: 'PATCH');
        } finally {
            self::assertSame('uncertain', $this->latest()['state']);
        }
    }

    public function testKnownValidationRejectionIsRecordedAndCanBeRetried(): void
    {
        $responses = [
            new MockResponse('{"errors":{"document_number":["taken"]}}', ['http_code' => 422]),
            new MockResponse(json_encode(['data' => [
                'id' => 501,
                'document_number' => $this->document()['document_number'],
                'type' => 'bill',
                'contact_id' => 123,
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]),
        ];
        $http = new MockHttpClient($responses);
        $request = new Request(new NullLogger(), $http, $this->journal);
        self::assertArrayHasKey('document_number', $request->send('/api/documents', $this->document())['errors']);
        self::assertSame('rejected', $this->latest()['state']);

        self::assertSame(501, $request->send('/api/documents', $this->document())['data']['id']);
        self::assertSame('confirmed', $this->latest()['state']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM publication_write_attempts'));
    }

    public function testPendingReservationWithoutHttpCannotBeRepeated(): void
    {
        $this->journal->reserve(1, 'POST', '/api/documents', $this->document());
        self::assertSame('pending', $this->latest()['state']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pendente');
        $this->journal->reserve(1, 'POST', '/api/documents', $this->document());
    }

    public function testJournalStoresOnlyFingerprintsNotPersonalOrFinancialValues(): void
    {
        $body = $this->document();
        $body['document_number'] = 'PDC_12345678909-2026-08';
        $body['notes'] = 'sensitive-test-only-payload';
        $this->journal->reserve(1, 'POST', '/api/documents', $body);

        $row = $this->connection->fetchAssociative('SELECT * FROM publication_write_attempts');
        self::assertIsArray($row);
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('12345678909', $encoded);
        self::assertStringNotContainsString('sensitive-test-only-payload', $encoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row['document_fingerprint']);
    }

    public function testReadRequestsBypassJournalAndRemainReadOnly(): void
    {
        $http = new MockHttpClient(new MockResponse('{"data":[]}'));
        $request = new Request(new NullLogger(), $http, $this->journal);
        self::assertSame([], $request->send('/api/documents', method: 'GET')['data']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM publication_write_attempts'));
    }

    public function testPendingAttemptsAreVisibleWithoutSensitiveDocuments(): void
    {
        $body = $this->document();
        $body['document_number'] = 'PDC_12345678909-2026-08';
        $this->journal->reserve(1, 'POST', '/api/documents', $body);

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('publication:pending'));
        self::assertSame(0, $tester->execute(['--limit' => '25']));
        $output = $tester->getDisplay();
        self::assertStringContainsString('aguardando conciliação', $output);
        self::assertStringContainsString('pending', $output);
        self::assertStringNotContainsString('12345678909', $output);
        self::assertStringNotContainsString($body['document_number'], $output);
        self::assertStringContainsString('empresa=1', $output);
    }

    public function testApplicationRequestCannotSilentlyOmitJournal(): void
    {
        $request = static::getContainer()->get(Request::class);
        $journal = new \ReflectionProperty(Request::class, 'publicationJournal');
        self::assertInstanceOf(PublicationWriteJournal::class, $journal->getValue($request));
    }

    public function testJournalCannotBeWipedByStagingReset(): void
    {
        self::assertNotContains('publication_write_attempts', StagingSchema::TABLES);
    }

    public function testJournalDoesNotProtectWritesWithoutRequiredIdentity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não identificados');
        $this->journal->reserve(1, 'POST', '/api/documents', ['notes' => 'no identity']);
    }
}
