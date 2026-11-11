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
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Support\FieldStudioResponse;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Contract for FRRA on local test databases; remote writes are simulated. */
final class FrraFinancialContractTest extends KernelTestCase
{
    private const string CPF = '00011122233';
    private const string ANNUAL_FRRA_NUMBER = 'FRRA_00011122233-2026-12';
    private EntityManagerInterface $em;
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        self::bootKernel();
        foreach ([
            'AKAUNTING_API_BASE_URL' => 'https://accounting.example.test',
            'AKAUNTING_AUTH_USER' => 'qa@example.test',
            'AKAUNTING_AUTH_TOKEN' => 'fake-test-token',
        ] as $name => $value) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'DELETE FROM invoices WHERE document_number = :number',
            ['number' => self::ANNUAL_FRRA_NUMBER],
        );
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM invoices WHERE document_number = :number',
            ['number' => self::ANNUAL_FRRA_NUMBER],
        );
        $this->em->clear();
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        parent::tearDown();
    }

    private function member(Request $http): Cooperado
    {
        $dates = static::getContainer()->get(Dates::class);
        $dates->setInicio(new DateTime('2026-09-01'));
        $dates->setEvaluationTime(new DateTime('2026-09-10'));
        $member = new Cooperado(
            entityManager: $this->em,
            dates: $dates,
            numberFormatter: new NumberFormatter('pt_BR', NumberFormatter::CURRENCY),
            documents: static::getContainer()->get(Documents::class),
            request: $http,
            documentConfiguration: new DocumentConfiguration(),
            anoFiscal: 2026,
            mes: 9,
        );
        return $member
            ->setName('Cooperado fictício de teste')
            ->setTaxNumber(self::CPF)
            ->setDependentes(0)
            ->setAkauntingContactId(100);
    }

    public function testFrraDocumentKeepsItsOwnCategoryAndDoesNotWriteProductionOrTaxes(): void
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
            self::assertSame(self::ANNUAL_FRRA_NUMBER, $body['document_number']);
            self::assertSame((string) getenv('AKAUNTING_FRRA_CATEGORY_ID'), (string) $body['category_id']);
            self::assertSame(self::CPF, $body['contact_tax_number']);
            self::assertSame('100', (string) $body['contact_id']);
            self::assertSame('BRL', $body['currency_code']);
            self::assertSame(substr($body['due_at'], 0, 7), $body['custom_fields']['transaction_of_month']);
            self::assertArrayNotHasKey('production_metadata_migrated', $body['custom_fields']);
            self::assertCount(1, $body['items'], 'FRRA must not include Bruto, Auxílio, INSS or IRRF.');
            self::assertSame('FRRA', $body['items'][0]['name']);
            self::assertSame((string) new DocumentConfiguration()->getDocumentItemId('frra'), (string) $body['items'][0]['item_id']);
            self::assertEqualsWithDelta(500.0, (float) $body['items'][0]['price'], 0.001);

            return new MockResponse(json_encode(['data' => [
                ...$body,
                'id' => 994902,
                'status' => 'draft',
                'amount' => 500,
                'issued_at' => $body['issued_at'],
                'due_at' => $body['due_at'],
                'category' => ['name' => 'Cooperado > Produção > FRRA', 'type' => 'expense'],
                'contact' => [
                    'name' => 'Cooperado fictício de teste',
                    'type' => 'vendor',
                    'reference' => null,
                    'tax_number' => self::CPF,
                ],
                'items' => ['data' => $body['items']],
                'field_studio' => FieldStudioResponse::fromRequest($body),
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $member = $this->member(new Request(new NullLogger(), $http));
        $frra = $member->getFrra();
        $frra->getValues()->setFrra(500.0)->setUpdated();
        $frra->save();

        self::assertCount(1, $requests, 'FRRA must not send a second production/tax request.');
        $saved = $this->em->getConnection()->fetchAssociative(
            'SELECT document_number, category_id, amount, type FROM invoices WHERE id = 994902',
        );
        self::assertIsArray($saved, 'Confirmed fake API reply must be recorded in isolated staging.');
        self::assertSame(self::ANNUAL_FRRA_NUMBER, $saved['document_number']);
        self::assertSame((int) getenv('AKAUNTING_FRRA_CATEGORY_ID'), (int) $saved['category_id']);
        self::assertEqualsWithDelta(500.0, (float) $saved['amount'], 0.01);
        self::assertSame('bill', $saved['type']);
    }

    public function testIndividualsProductionPublishesAllFinancialItemsAndTaxLiability(): void
    {
        $writes = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$writes): MockResponse {
            if ($method === 'GET') {
                self::assertStringStartsWith('https://accounting.example.test/api/contacts/20', $url);
                return new MockResponse(json_encode(['data' => [
                    'id' => 20, 'company_id' => 1, 'type' => 'vendor',
                    'name' => 'Fornecedor de tributos fictício', 'tax_number' => '12345678000190',
                    'currency_code' => 'BRL',
                ]], JSON_THROW_ON_ERROR));
            }

            self::assertSame('POST', $method);
            self::assertSame('https://accounting.example.test/api/documents?include_field_studio=1', $url);
            $body = $options['body'];
            if (is_string($body)) {
                parse_str($body, $body);
            }
            self::assertIsArray($body);
            $writes[] = $body;
            $isProduction = str_starts_with((string) $body['document_number'], 'PDC_');
            $id = $isProduction ? 994903 : 994904;
            $amount = array_sum(array_map(static fn (array $row): float =>
                (float) $row['price'] * (float) $row['quantity'], $body['items']));
            return new MockResponse(json_encode(['data' => [
                ...$body,
                'id' => $id,
                'company_id' => 1,
                'amount' => abs($amount),
                'issued_at' => $body['issued_at'],
                'due_at' => $body['due_at'],
                'status' => 'draft',
                'category' => ['name' => $isProduction ? 'Produção cooperativista' : 'Guia de tributos', 'type' => 'expense'],
                'contact' => [
                    'name' => $body['contact_name'], 'type' => 'vendor',
                    'reference' => null, 'tax_number' => $body['contact_tax_number'],
                ],
                'items' => ['data' => $body['items']],
                'field_studio' => FieldStudioResponse::fromRequest($body),
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $this->em->getConnection()->executeStatement('DELETE FROM invoices WHERE id IN (994903, 994904)');
        try {
            $member = $this->member(new Request(new NullLogger(), $http));
            $member->getProducaoCooperativista()->getValues()->setBaseProducao(12000.0);
            $member->getProducaoCooperativista()->save();

            self::assertCount(2, $writes, 'Individual production needs its own document and tax liability.');
            $production = $writes[0];
            $taxLiability = $writes[1];
            self::assertSame('PDC_' . self::CPF . '-TRAB-2026-09-FIN-2026-10-PAG-2026-11', $production['document_number']);
            self::assertSame((int) getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'), (int) $production['category_id']);
            self::assertSame((string) 100, (string) $production['contact_id']);
            self::assertSame('bill', $production['type']);
            self::assertSame(
                '2026-11',
                $production['custom_fields']['transaction_of_month'],
                'A conta a pagar aos cooperados corresponde ao mês de pagamento, não ao mês das receitas.'
            );
            self::assertStringContainsString('Produção realizada no mês: 2026-09', $production['notes']);
            self::assertStringContainsString('Competência: 2026-10', $production['notes']);

            $items = [];
            foreach ($production['items'] as $item) {
                $items[$item['name']] = $item;
            }
            self::assertEqualsCanonicalizing(
                ['Ajuda de custo', 'Bruto produção', 'INSS', 'IRRF'],
                array_keys($items),
            );
            self::assertEqualsWithDelta(8600.0, (float) $items['Bruto produção']['price'], 0.01);
            self::assertEqualsWithDelta(2400.0, (float) $items['Ajuda de custo']['price'], 0.01);
            self::assertSame(-1, (int) $items['INSS']['quantity']);
            self::assertSame(-1, (int) $items['IRRF']['quantity']);
            self::assertStringContainsString('FRRA: ', (string) $production['notes']);
            self::assertStringNotContainsString(
                'FRRA',
                implode(',', array_keys($items)),
                'Annual reserve must not be charged twice in the monthly production bill.'
            );

            self::assertStringStartsWith('INSS_IRRF_', (string) $taxLiability['document_number']);
            self::assertSame('bill', $taxLiability['type']);
            self::assertNotSame((string) $production['contact_id'], (string) $taxLiability['contact_id']);
            self::assertContains('INSS Cooperado fictício de teste', array_column($taxLiability['items'], 'name'));
            self::assertContains('IRRF Cooperado fictício de teste', array_column($taxLiability['items'], 'name'));

            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT id, document_number, category_id, contact_id, amount FROM invoices WHERE id IN (994903, 994904) ORDER BY id',
            );
            self::assertCount(2, $rows);
            self::assertSame((int) getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'), (int) $rows[0]['category_id']);
            self::assertSame('PDC_' . self::CPF . '-TRAB-2026-09-FIN-2026-10-PAG-2026-11', $rows[0]['document_number']);
            self::assertEqualsWithDelta(8600.0, (float) $rows[0]['amount'] + (float) $items['INSS']['price'] + (float) $items['IRRF']['price'] - (float) $items['Ajuda de custo']['price'], 0.01);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM invoices WHERE id IN (994903, 994904)');
        }
    }

    public function testAnnualFrraKeepsOneDocumentWithDistinctMonthlyReserveItems(): void
    {
        $remote = null;
        $methods = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$remote, &$methods): MockResponse {
            self::assertStringStartsWith('https://accounting.example.test/api/documents', $url);
            $methods[] = $method;
            if ($method === 'GET') {
                self::assertIsArray($remote, 'The draft must exist before readback.');
                return new MockResponse(json_encode(['data' => $remote], JSON_THROW_ON_ERROR));
            }
            self::assertContains($method, ['POST', 'PATCH']);
            $body = $options['body'];
            if (is_string($body)) {
                parse_str($body, $body);
            }
            self::assertSame(self::ANNUAL_FRRA_NUMBER, $body['document_number']);
            self::assertSame((int) getenv('AKAUNTING_FRRA_CATEGORY_ID'), (int) $body['category_id']);
            self::assertSame(100, (int) $body['contact_id']);
            $items = array_values(array_map(static fn (array $item, int $index): array => [
                ...$item,
                'id' => 994920 + $index,
                'item_id' => (int) ($item['item_id'] ?? 0),
                'price' => (float) $item['price'],
                'quantity' => (int) $item['quantity'],
                'total' => (float) $item['total'],
            ], $body['items'], array_keys($body['items'])));
            $remote = [
                ...$body,
                'id' => 994902,
                'company_id' => 1,
                'category_id' => (int) $body['category_id'],
                'contact_id' => (int) $body['contact_id'],
                'currency_rate' => (int) $body['currency_rate'],
                'status' => 'draft',
                'amount' => array_sum(array_map(static fn (array $item): float =>
                    (float) $item['price'] * (float) $item['quantity'], $items)),
                'category' => ['name' => 'Cooperado > Produção > FRRA', 'type' => 'expense'],
                'contact' => [
                    'name' => 'Cooperado fictício de teste', 'type' => 'vendor',
                    'reference' => null, 'tax_number' => self::CPF,
                ],
                'items' => ['data' => $items],
                'field_studio' => FieldStudioResponse::fromRequest($body),
            ];
            return new MockResponse(json_encode(['data' => $remote], JSON_THROW_ON_ERROR), [
                'http_code' => $method === 'POST' ? 201 : 200,
            ]);
        });
        $request = new Request(new NullLogger(), $http);
        $dates = static::getContainer()->get(Dates::class);
        $first = $this->member($request);
        $dates->setInicio(new DateTime('2026-08-01'));
        $first->getFrra()->getValues()->setFrra(100.0)->setUpdated();
        $first->getFrra()->save();

        // A retry for the *same* competence must replace the monthly line,
        // not create a second reservation for the following month.
        $repeatFirst = $this->member($request);
        $dates->setInicio(new DateTime('2026-08-01'));
        $repeatFirst->getFrra()->getValues()->setFrra(100.0)->setUpdated();
        $repeatFirst->getFrra()->save();
        self::assertCount(1, $remote['items']['data']);
        self::assertEqualsWithDelta(100.0, (float) $remote['amount'], 0.01);

        $next = $this->member($request);
        $dates->setInicio(new DateTime('2026-09-01'));
        $next->getFrra()->getValues()->setFrra(200.0)->setUpdated();
        $next->getFrra()->save();

        $repeatNext = $this->member($request);
        $dates->setInicio(new DateTime('2026-09-01'));
        $repeatNext->getFrra()->getValues()->setFrra(200.0)->setUpdated();
        $repeatNext->getFrra()->save();

        self::assertSame(['POST', 'GET', 'GET', 'PATCH', 'GET', 'GET', 'PATCH', 'GET', 'GET', 'PATCH'], $methods);
        self::assertIsArray($remote);
        self::assertSame(self::ANNUAL_FRRA_NUMBER, $remote['document_number']);
        self::assertSame((int) getenv('AKAUNTING_FRRA_CATEGORY_ID'), (int) $remote['category_id']);
        $items = $remote['items']['data'];
        self::assertCount(2, $items, 'A second work month adds a reserve line rather than a second annual document.');
        self::assertSame(['FRRA', 'FRRA'], array_column($items, 'name'));
        self::assertEqualsWithDelta(300.0, (float) $remote['amount'], 0.01);
        self::assertCount(2, array_unique(array_column($items, 'description')));
    }

    /** @return array<string, array{string, string}> */
    public static function workPeriods(): array
    {
        return [
            'January' => ['2026-01-01', 'PDC_' . self::CPF . '-2026-02'],
            'September' => ['2026-09-01', 'PDC_' . self::CPF . '-TRAB-2026-09-FIN-2026-10-PAG-2026-11'],
            'November' => ['2026-11-01', 'PDC_' . self::CPF . '-TRAB-2026-11-FIN-2026-12-PAG-2027-01'],
        ];
    }

    #[DataProvider('workPeriods')]
    public function testProductionNumberNeverCollidesWithAnnualFrra(string $start, string $expectedProduction): void
    {
        $member = $this->member(new Request(new NullLogger(), new MockHttpClient()));
        $dates = static::getContainer()->get(Dates::class);
        $dates->setInicio(new DateTime($start));
        $dates->setEvaluationTime(new DateTime('2026-08-15'));
        self::assertSame($expectedProduction, $member->getProducaoCooperativista()->getPreviewDocumentNumber());
        self::assertSame(self::ANNUAL_FRRA_NUMBER, $member->getFrra()->getPreviewDocumentNumber());
        self::assertNotSame($member->getProducaoCooperativista()->getPreviewDocumentNumber(), $member->getFrra()->getPreviewDocumentNumber());
    }
}
