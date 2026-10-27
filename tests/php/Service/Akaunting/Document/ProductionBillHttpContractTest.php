<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Akaunting\Document;

use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use App\Service\Cooperado;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use NumberFormatter;
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

    private function member(Request $http): Cooperado
    {
        $dates = static::getContainer()->get(Dates::class);
        $dates->setInicio(new DateTime('2026-07-01'));
        $member = new Cooperado(
            entityManager: $this->em,
            dates: $dates,
            numberFormatter: new NumberFormatter('pt_BR', NumberFormatter::CURRENCY),
            documents: static::getContainer()->get(Documents::class),
            request: $http,
            documentConfiguration: new DocumentConfiguration(),
            anoFiscal: 2026,
            mes: 7,
        );
        $member->setName('Cooperativa Prestadora Exemplo');
        $member->setTaxNumber('12345678000190');
        $member->setAkauntingContactId(100);
        $member->getProducaoCooperativista()->getValues()->setBaseProducao(2000);
        return $member;
    }

    public function testProductionBillIsSentAndPersistedWithBusinessCorrectFields(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://accounting.example.test/api/documents', $url);
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
