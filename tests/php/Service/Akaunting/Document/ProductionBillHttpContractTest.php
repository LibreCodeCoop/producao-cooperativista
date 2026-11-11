<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Document;

use App\Entity\Producao\Invoice;
use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use App\Service\Cooperado;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
use App\Tests\Support\FieldStudioResponse;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Actual accounting payload + API serialization, no internal method mocks. */
final class ProductionBillHttpContractTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $env = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement("DELETE FROM invoices WHERE document_number = 'PDC_12345678000190-2026-08'");
        foreach ([
            'AKAUNTING_API_BASE_URL' => 'https://accounting.example.test',
            'AKAUNTING_AUTH_USER' => 'readonly@example.test',
            'AKAUNTING_AUTH_TOKEN' => 'test-token',
        ] as $name => $value) {
            $this->env[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM invoices WHERE document_number = 'PDC_12345678000190-2026-08'");
        $this->em->clear();
        foreach ($this->env as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        parent::tearDown();
    }

    private function member(Request $http, string $workMonth = '2026-07-01'): Cooperado
    {
        $dates = static::getContainer()->get(Dates::class);
        $dates->setInicio(new DateTime($workMonth));
        $dates->setEvaluationTime(new DateTime('2026-10-20'));
        $member = new Cooperado(
            entityManager: $this->em,
            dates: $dates,
            numberFormatter: new NumberFormatter('pt_BR', NumberFormatter::CURRENCY),
            documents: static::getContainer()->get(Documents::class),
            request: $http,
            documentConfiguration: new DocumentConfiguration(),
            anoFiscal: (int) substr($workMonth, 0, 4),
            mes: (int) substr($workMonth, 5, 2),
        );
        $member->setName('Cooperativa Prestadora Exemplo');
        $member->setTaxNumber('12345678000190');
        $member->setAkauntingContactId(100);
        $member->getProducaoCooperativista()->getValues()->setBaseProducao(2000);
        return $member;
    }

    public function testPreviewOfExistingDraftDoesNotReadOrWriteRemoteAkaunting(): void
    {
        $draft = new Invoice();
        $draft->fromArray([
            'id' => 994902,
            'type' => 'bill',
            'issued_at' => '2026-08-01 00:00:00',
            'due_at' => '2026-08-04 00:00:00',
            'transaction_of_month' => '2026-08',
            'amount' => 100,
            'currency_code' => 'BRL',
            'document_number' => 'PDC_12345678000190-2026-08',
            'tax_number' => '12345678000190',
            'contact_id' => 100,
            'contact_name' => 'Cooperativa Prestadora Exemplo',
            'contact_type' => 'vendor',
            'category_id' => 44,
            'category_name' => 'Produção',
            'category_type' => 'expense',
            'metadata' => ['status' => 'draft'],
        ]);
        $this->em->persist($draft);
        $this->em->flush();
        $database = $this->em->getConnection();
        $before = $database->fetchAssociative('SELECT * FROM invoices WHERE id = 994902');

        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('A read-only preview must not query Akaunting');
        });
        $member = $this->member(new Request(new NullLogger(), $http));
        self::assertSame(2000.0, $member->getProducaoCooperativista()->getValues()->getBaseProducao());
        self::assertSame($before, $database->fetchAssociative('SELECT * FROM invoices WHERE id = 994902'));
        $database->executeStatement('DELETE FROM invoices WHERE id = 994902');
    }

    public function testProductionBillIsSentAndPersistedWithBusinessCorrectFields(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://accounting.example.test/api/documents?include_field_studio=1', $url);
            $body = $options['body'];
            if (is_string($body)) {
                parse_str($body, $body);
            }
            $requests[] = $body;
            self::assertSame('bill', $body['type']);
            self::assertSame('draft', $body['status']);
            self::assertSame('PDC_12345678000190-2026-08', $body['document_number']);
            self::assertSame('12345678000190', $body['contact_tax_number']);
            self::assertSame('100', (string) $body['contact_id']);
            self::assertSame((string) getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'), (string) $body['category_id']);
            self::assertSame('BRL', $body['currency_code']);
            self::assertSame('Bruto produção', $body['items'][0]['name']);
            self::assertEqualsWithDelta(2000.0, (float) $body['items'][0]['price'], 0.01);
            self::assertStringContainsString('Produção realizada no mês: 2026-07', $body['notes']);
            self::assertSame('2026-08', $body['custom_fields']['transaction_of_month']);
            self::assertArrayNotHasKey('production_metadata_migrated', $body['custom_fields']);

            return new MockResponse(json_encode(['data' => [
                ...$body,
                'id' => 994901,
                'status' => 'draft',
                'amount' => 2000,
                'issued_at' => $body['issued_at'],
                'due_at' => $body['due_at'],
                'category' => ['name' => 'Produção cooperativista', 'type' => 'expense'],
                'contact' => [
                    'name' => 'Cooperativa Prestadora Exemplo',
                    'type' => 'vendor',
                    'reference' => null,
                    'tax_number' => '12345678000190',
                ],
                'items' => ['data' => $body['items']],
                'field_studio' => FieldStudioResponse::fromRequest($body),
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $request = new Request(new NullLogger(), $http);
        $member = $this->member($request);
        $member->getProducaoCooperativista()->save();
        self::assertCount(1, $requests);

        $record = $this->em->getConnection()->fetchAssociative('SELECT * FROM invoices WHERE id = 994901');
        self::assertIsArray($record);
        self::assertSame('PDC_12345678000190-2026-08', $record['document_number']);
        self::assertSame('bill', $record['type']);
        self::assertEqualsWithDelta(2000.0, (float) $record['amount'], 0.001);
    }

    /** @return array<string, array{string, list<array<string, mixed>>, string}> */
    public static function protectedHistoricalBillCases(): array
    {
        return [
            'matching work period with accumulated FRRA' => [
                'Produção realizada no mês: 2026-07',
                [['name' => 'FRRA', 'description' => 'Referente ao ano/mês: 2026-06', 'price' => 980]],
                'FRRA acumulado',
            ],
            'document number reused across different work periods' => [
                'Produção realizada no mês: 2026-05',
                [['name' => 'Bruto produção', 'description' => '', 'price' => 980]],
                'outra competência',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedHistoricalBillCases')]
    public function testProtectedLegacyFrraBillCannotBeModifiedByProductionPublication(
        string $remoteNotes,
        array $remoteItems,
        string $expectedMessage,
    ): void {
        $legacyNumber = 'PDC_12345678000190-2026-08';
        $this->em->getConnection()->executeStatement('DELETE FROM invoices WHERE id = 994905');
        $draft = new Invoice();
        $draft->fromArray([
            'id' => 994905,
            'type' => 'bill',
            'issued_at' => '2026-08-01 00:00:00',
            'due_at' => '2026-12-08 00:00:00',
            'transaction_of_month' => '2026-12',
            'amount' => 980.0,
            'currency_code' => 'BRL',
            'document_number' => $legacyNumber,
            'tax_number' => '12345678000190',
            'contact_id' => 100,
            'contact_name' => 'Cooperativa Prestadora Exemplo',
            'contact_type' => 'vendor',
            'category_id' => 11,
            'category_name' => 'Produção',
            'category_type' => 'expense',
            'metadata' => ['status' => 'draft', 'notes' => 'Produção realizada no mês: 2026-07'],
        ]);
        $this->em->persist($draft);
        $this->em->flush();
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM invoices WHERE id = 994905');
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (
            &$calls,
            $legacyNumber,
            $remoteNotes,
            $remoteItems,
        ): MockResponse {
            $calls[] = $method;
            if ($method !== 'GET') {
                throw new \LogicException('Protected FRRA documents must never receive any write');
            }
            self::assertStringContainsString('/api/documents/994905', $url);
            return new MockResponse(json_encode(['data' => [
                'id' => 994905, 'company_id' => 1, 'type' => 'bill',
                'document_number' => $legacyNumber, 'status' => 'draft',
                'contact_id' => 100, 'notes' => $remoteNotes,
                'items' => ['data' => $remoteItems],
            ]], JSON_THROW_ON_ERROR));
        });
        try {
            $member = $this->member(new Request(new NullLogger(), $http));
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage($expectedMessage);
            $member->getProducaoCooperativista()->save();
        } finally {
            self::assertSame(['GET'], $calls);
            self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM invoices WHERE id = 994905'));
            $this->em->getConnection()->executeStatement('DELETE FROM invoices WHERE id = 994905');
        }
    }

    public function testSeptemberPublicationKeepsOldFrraDraftUntouchedAndUsesNovemberPaymentMonth(): void
    {
        $legacyNumber = 'PDC_12345678000190-2026-10';
        $newNumber = 'PDC_12345678000190-TRAB-2026-09-FIN-2026-10-PAG-2026-11';
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM invoices WHERE id IN (994906, 994907)');
        $draft = new Invoice();
        $draft->fromArray([
            'id' => 994906, 'type' => 'bill',
            'issued_at' => '2026-09-07 00:00:00', 'due_at' => '2026-12-08 00:00:00',
            'transaction_of_month' => '2026-12', 'amount' => 1550.0,
            'currency_code' => 'BRL', 'document_number' => $legacyNumber,
            'tax_number' => '12345678000190', 'contact_id' => 100,
            'contact_name' => 'Cooperativa Prestadora Exemplo', 'contact_type' => 'vendor',
            'category_id' => 11, 'category_name' => 'Produção', 'category_type' => 'expense',
            'metadata' => ['status' => 'draft', 'notes' => 'Produção realizada no mês: 2026-07'],
        ]);
        $this->em->persist($draft);
        $this->em->flush();
        $original = $db->fetchAssociative('SELECT * FROM invoices WHERE id = 994906');
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $newNumber): MockResponse {
            $calls[] = $method;
            self::assertSame('POST', $method);
            self::assertStringContainsString('/api/documents?include_field_studio=1', $url);
            $body = $options['body'];
            if (is_string($body)) {
                parse_str($body, $body);
            }
            self::assertSame($newNumber, $body['document_number']);
            self::assertSame('2026-11', $body['custom_fields']['transaction_of_month']);
            self::assertStringContainsString('Produção realizada no mês: 2026-09', $body['notes']);
            self::assertStringContainsString('Competência: 2026-10', $body['notes']);
            self::assertStringStartsWith('2026-11-', $body['due_at']);
            return new MockResponse(json_encode(['data' => [
                ...$body, 'id' => 994907, 'company_id' => 1, 'status' => 'draft',
                'amount' => (float) $body['amount'],
                'category' => ['name' => 'Produção', 'type' => 'expense'],
                'contact' => ['name' => 'Cooperativa Prestadora Exemplo', 'type' => 'vendor', 'reference' => null,
                    'tax_number' => '12345678000190'],
                'items' => ['data' => $body['items']],
                'field_studio' => FieldStudioResponse::fromRequest($body),
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        try {
            $member = $this->member(new Request(new NullLogger(), $http), '2026-09-01');
            $member->getProducaoCooperativista()->save();
            self::assertSame(['POST'], $calls);
            self::assertSame($original, $db->fetchAssociative('SELECT * FROM invoices WHERE id = 994906'));
            $created = $db->fetchAssociative('SELECT document_number, transaction_of_month FROM invoices WHERE id = 994907');
            self::assertSame($newNumber, $created['document_number']);
            self::assertSame('2026-11', $created['transaction_of_month']);
        } finally {
            $db->executeStatement('DELETE FROM invoices WHERE id IN (994906, 994907)');
        }
    }

    public function testDuplicatePaidDocumentIsRejectedAfterExactReadbackWithoutSecondWrite(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $method;
            if ($method === 'POST') {
                return new MockResponse(
                    '{"errors":{"document_number":["taken"]}}',
                    ['http_code' => 422]
                );
            }
            self::assertSame('GET', $method);
            return new MockResponse(json_encode(['data' => [
                ['id' => 771, 'document_number' => 'PDC_12345678000190-2026-08', 'status' => 'paid'],
            ]], JSON_THROW_ON_ERROR));
        });
        $request = new Request(new NullLogger(), $http);
        $member = $this->member($request);
        try {
            $member->getProducaoCooperativista()->save();
            self::fail('A paid document may never be overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('finalizado', $exception->getMessage());
            self::assertSame(['POST', 'GET'], $calls);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoices WHERE id = 994901'));
    }
}
