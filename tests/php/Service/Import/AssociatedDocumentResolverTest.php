<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use App\Provider\Akaunting\Request;
use App\Service\Import\AssociatedDocumentResolver;
use DomainException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssociatedDocumentResolverTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach ([
            'AKAUNTING_API_BASE_URL' => 'https://accounting.example.test',
            'AKAUNTING_AUTH_USER' => 'integration@example.test',
            'AKAUNTING_AUTH_TOKEN' => 'synthetic-test-token',
            'AKAUNTING_COMPANY_ID' => '1',
        ] as $key => $value) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    private function resolver(callable $handle): AssociatedDocumentResolver
    {
        $client = new MockHttpClient($handle);
        $dataset = new Dataset(new Request(new NullLogger(), $client));
        return new AssociatedDocumentResolver($dataset, new ParseText());
    }

    /** @return list<array{document_id:int}> */
    private function transactions(array $documentIds): array
    {
        return array_map(static fn (int $id): array => ['document_id' => $id], $documentIds);
    }

    public function testInvoiceAndBillAreResolvedInTwoHttpBatchesWithoutHistoricalStaging(): void
    {
        $requests = [];
        $resolver = $this->resolver(static function (string $method, string $url) use (&$requests): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://accounting.example.test/api/documents', strtok($url, '?'));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('1', (string) $query['company_id']);
            $requests[] = $query['search'];
            if (str_starts_with($query['search'], 'type:invoice')) {
                self::assertSame('type:invoice id:71,72,73', $query['search']);
                return new MockResponse(json_encode(['data' => [
                    [
                        'id' => 71, 'company_id' => 1, 'type' => 'invoice',
                        'notes' => "Transação do mês: 2026-04\nCNPJ cliente: 11111111000111",
                        'contact' => ['reference' => null, 'tax_number' => '11111111000111'],
                    ],
                    [
                        'id' => 72, 'company_id' => 1, 'type' => 'invoice',
                        'notes' => '', 'contact' => ['reference' => '33333333000133'],
                    ],
                ], 'links' => ['next' => null]], JSON_THROW_ON_ERROR));
            }
            self::assertSame('type:bill id:73', $query['search']);
            return new MockResponse(json_encode(['data' => [[
                'id' => 73, 'company_id' => 1, 'type' => 'bill',
                'notes' => '', 'contact' => ['reference' => '22222222000122'],
            ]]], JSON_THROW_ON_ERROR));
        });

        $documents = $resolver->resolve($this->transactions([71, 72, 71, 73]), 1);

        self::assertSame(
            ['type:invoice id:71,72,73', 'type:bill id:73'],
            $requests
        );
        self::assertCount(3, $documents);
        self::assertSame('11111111000111', $documents[71]['customer_reference']);
        self::assertSame('33333333000133', $documents[72]['customer_reference']);
        self::assertSame('22222222000122', $documents[73]['customer_reference']);
        self::assertStringContainsString(
            'Transação do mês: 2026-04',
            json_decode($documents[71]['metadata'], true, 512, JSON_THROW_ON_ERROR)['notes']
        );
    }

    public function testUnknownCustomerNeverBecomesCooperativeCnpj(): void
    {
        $resolver = $this->resolver(static fn (): MockResponse => new MockResponse(
            '{"data":[{"id":91,"type":"invoice","company_id":1,"notes":"","contact":{}}]}'
        ));
        $documents = $resolver->resolve($this->transactions([91]), 1);
        self::assertSame('', $documents[91]['customer_reference']);
    }

    public function testHistoricalReferenceMissingFromAkauntingFailsWithoutInventingCompetence(): void
    {
        $requests = [];
        $resolver = $this->resolver(static function (string $method, string $url) use (&$requests): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $requests[] = $query['search'];
            return new MockResponse('{"data":[],"links":{"next":null}}');
        });

        try {
            $resolver->resolve($this->transactions([901]), 1);
            self::fail('Source references must not be silently attributed to their payment date.');
        } catch (DomainException $error) {
            self::assertStringContainsString('IDs: 901', $error->getMessage());
            self::assertStringContainsString('competência não será inferida', $error->getMessage());
            self::assertSame(['type:invoice id:901', 'type:bill id:901'], $requests);
        }
    }

    public function testDocumentOfAnotherCompanyIsRejected(): void
    {
        $resolver = $this->resolver(static fn (): MockResponse => new MockResponse('{"data":[{"id":901,"company_id":2,"type":"invoice"}]}'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fora do conjunto solicitado');
        $resolver->resolve($this->transactions([901]), 1);
    }

    public function testSixtyOneDocumentsAreResolvedInTwoBoundedRequests(): void
    {
        $requests = [];
        $resolver = $this->resolver(static function (string $method, string $url) use (&$requests): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertStringStartsWith('type:invoice id:', $query['search']);
            $ids = array_map(intval(...), explode(',', substr($query['search'], strlen('type:invoice id:'))));
            self::assertLessThanOrEqual(50, count($ids));
            $requests[] = count($ids);
            $documents = array_map(
                static fn (int $id): array => [
                    'id' => $id,
                    'type' => 'invoice',
                    'company_id' => 1,
                    'notes' => '',
                    'contact' => ['reference' => '55555555000155'],
                ],
                $ids
            );
            return new MockResponse(json_encode(['data' => $documents], JSON_THROW_ON_ERROR));
        });
        $documents = $resolver->resolve($this->transactions(range(1, 61)), 1);
        self::assertSame([50, 11], $requests);
        self::assertCount(61, $documents);
    }

    public function testUnlinkedTransactionsDoNotFetchAnyAkauntingDocuments(): void
    {
        $resolver = $this->resolver(static function (): never {
            self::fail('An unlinked movement must not generate extra API calls.');
        });
        self::assertSame([], $resolver->resolve([['document_id' => null], [], ['document_id' => 0]], 1));
    }
}
