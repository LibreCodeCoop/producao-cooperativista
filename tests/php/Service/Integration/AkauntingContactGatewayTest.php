<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Provider\Akaunting\Request as AkauntingRequest;
use App\Service\Integration\AkauntingContactGateway;
use Doctrine\DBAL\Connection;
use DomainException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The real Akaunting Laravel ACL requires search=type:vendor for contacts;
 * the Employees module has a different resource and ID namespace.
 */
final class AkauntingContactGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('AKAUNTING_API_BASE_URL=https://akaunting.example.test');
        putenv('AKAUNTING_AUTH_USER=api-test-user');
        putenv('AKAUNTING_AUTH_TOKEN=local-fixture');
        putenv('AKAUNTING_COMPANY_ID=1');
    }

    protected function tearDown(): void
    {
        foreach (['AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER', 'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID'] as $env) {
            putenv($env);
        }
    }

    /** @return array<string, mixed> */
    private function identity(string $type = 'vendor', int $company = 1): array
    {
        return [
            'id' => 20, 'type' => $type, 'company_id' => $company,
            'name' => 'Example Member', 'email' => 'example@example.test',
            'phone' => '', 'address' => '', 'city' => '', 'state' => '',
            'zip_code' => '', 'country' => '', 'currency_code' => 'BRL',
        ];
    }

    /** @return array<string, mixed> */
    private function vendor(string $number = '00000000000', int $company = 1): array
    {
        return [
            'id' => 20, 'type' => 'vendor', 'company_id' => $company,
            'name' => 'Example Member', 'tax_number' => $number,
            'email' => 'example@example.test', 'currency_code' => 'BRL',
        ];
    }

    /** @return array<string, mixed> */
    private function employee(string $number = '00000000000', ?string $salaryType = 'monthly', int $contactId = 20): array
    {
        return [
            'id' => 77, 'company_id' => 1,
            'birth_day' => '1980-07-01', 'gender' => 'other',
            'department' => ['id' => 4], 'salary' => 2100.50,
            'salary_type' => $salaryType, 'hired_at' => '2025-01-01',
            'contact' => [
                'id' => $contactId, 'company_id' => 1,
                'type' => 'employee', 'name' => 'Example Member',
                'tax_number' => $number, 'email' => 'example@example.test',
            ],
        ];
    }

    private function gateway(
        MockHttpClient $http,
        string $type = 'vendor',
        int $company = 1,
        array $employeeIds = [77],
        ?string $currency = 'BRL',
    ): AkauntingContactGateway {
        $identity = $this->identity($type, $company);
        $identity['currency_code'] = $currency;
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn($identity);
        $db->method('fetchFirstColumn')->willReturn($employeeIds);
        return new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), $http), $db);
    }

    public function testFindContactsByFiscalIdUsesCompanyAndIncludesOnlyEligibleTypes(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params): array {
                self::assertStringContainsString('deleted_at IS NULL', $sql);
                self::assertStringContainsString("type IN ('vendor', 'employee')", $sql);
                self::assertStringContainsString('REPLACE(', $sql);
                self::assertSame(['company' => 1, 'tax' => '52998224725'], $params);
                return [
                    ['id' => '28', 'name' => 'Cooperado', 'type' => 'vendor'],
                    ['id' => '63', 'name' => 'Outro cadastro', 'type' => 'employee'],
                ];
            }
        );
        $gateway = new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), new MockHttpClient()), $db);
        self::assertSame([
            ['id' => 28, 'name' => 'Cooperado', 'type' => 'vendor'],
            ['id' => 63, 'name' => 'Outro cadastro', 'type' => 'employee'],
        ], $gateway->findByTaxNumber('52998224725'));
    }

    public function testExplicitCreateUsesApiAndConfirmsIdentityWithoutCreatingUsersOrDocuments(): void
    {
        $db = $this->createMock(Connection::class);
        $lock = [];
        $db->method('fetchOne')->willReturnCallback(static function (string $query, array $params) use (&$lock): int {
            $lock[] = [$query, $params['lock_name']];
            self::assertLessThanOrEqual(64, strlen($params['lock_name']));
            return 1;
        });
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);
        $db->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => 74, 'company_id' => 1, 'type' => 'vendor',
            'name' => 'Luiz Amorim', 'tax_number' => '529.982.247-25',
        ]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString('/api/contacts', $url);
            parse_str((string) $options['body'], $body);
            self::assertSame('Luiz Amorim', $body['name']);
            self::assertSame('52998224725', $body['tax_number']);
            self::assertSame('1', $body['company_id']);
            self::assertSame('vendor', $body['type']);
            self::assertSame('BRL', $body['currency_code']);
            self::assertSame('BR', $body['country']);
            self::assertArrayNotHasKey('create_user', $body);
            self::assertArrayNotHasKey('contact_id', $body);
            return new MockResponse(json_encode(['data' => [
                'id' => 74, 'type' => 'vendor', 'company_id' => 1, 'tax_number' => '52998224725',
            ]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $gateway = new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), $http), $db);
        self::assertSame(74, $gateway->createMissingContact('Luiz Amorim', '52998224725'));
        self::assertCount(2, $lock);
        self::assertStringContainsString('GET_LOCK', $lock[0][0]);
        self::assertStringContainsString('RELEASE_LOCK', $lock[1][0]);
    }

    public function testExistingFiscalContactBlocksApiCreationAndReleasesLock(): void
    {
        $db = $this->createStub(Connection::class);
        $locks = [];
        $db->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$locks): int {
            $locks[] = $sql;
            return 1;
        });
        $db->method('fetchAllAssociative')->willReturn([
            ['id' => 14, 'name' => 'Já existente', 'type' => 'vendor'],
        ]);
        $http = new MockHttpClient(static fn (): never => throw new \LogicException('No POST should occur'));
        $gateway = new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), $http), $db);
        try {
            $gateway->createMissingContact('Cooperado', '52998224725');
            self::fail('Duplicate contact must not be created.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Já existe contato', $error->getMessage());
        }
        self::assertCount(2, $locks);
    }

    public function testUnavailableCreationLockPreventsWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn(0);
        $http = new MockHttpClient(static fn (): never => throw new \LogicException('No POST should occur'));
        $gateway = new AkauntingContactGateway(new AkauntingRequest(new NullLogger(), $http), $db);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('em andamento');
        $gateway->createMissingContact('Cooperado', '52998224725');
    }

    public function testVendorReadAddsLaravelAclSearchType(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = [$method, $url];
            return new MockResponse((string) json_encode(['data' => $this->vendor()]));
        });
        $contact = $this->gateway($http)->contact(20);

        self::assertSame('vendor', $contact['type']);
        self::assertTrue($contact['editable']);
        self::assertSame('00000000000', $contact['tax_number']);
        self::assertSame('GET', $seen[0][0]);
        self::assertStringContainsString('/api/contacts/20', $seen[0][1]);
        self::assertSame('type:vendor', $this->query($seen[0][1])['search']);
    }

    public function testVendorUpdateIncludesMandatoryCurrencyAndChecksReadback(): void
    {
        $value = '00000000000';
        $put = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$value, &$put): MockResponse {
            self::assertStringContainsString('/api/contacts/20', $url);
            self::assertSame('type:vendor', $this->query($url)['search']);
            if ($method === 'PUT') {
                $put++;
                parse_str((string) $options['body'], $body);
                self::assertSame('BRL', $body['currency_code']);
                self::assertSame('vendor', $body['type']);
                self::assertSame('Example Member', $body['name']);
                self::assertSame('52998224725', $body['tax_number']);
                self::assertSame('example@example.test', $body['email']);
                $value = $body['tax_number'];
            }
            return new MockResponse((string) json_encode(['data' => $this->vendor($value)]));
        });

        $this->gateway($http)->updateTaxNumber(20, '52998224725');
        self::assertSame(1, $put);
    }

    public function testEmployeeTaxNumberUsesContactApiWithoutChangingPayrollFields(): void
    {
        $value = '00000000000';
        $puts = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$value, &$puts): MockResponse {
            if ($method === 'PUT') {
                self::assertStringContainsString('/api/contacts/20', $url);
                self::assertSame('type:employee', $this->query($url)['search']);
                ++$puts;
                parse_str((string) $options['body'], $body);
                self::assertSame('employee', $body['type']);
                self::assertSame('52998224725', $body['tax_number']);
                self::assertSame('BRL', $body['currency_code']);
                self::assertArrayNotHasKey('amount', $body);
                self::assertArrayNotHasKey('salary_type', $body);
                self::assertArrayNotHasKey('hired_at', $body);
                self::assertArrayNotHasKey('department_id', $body);
                $value = $body['tax_number'];
                return new MockResponse((string) json_encode(['data' => [
                    'id' => 20, 'type' => 'employee', 'company_id' => 1,
                    'tax_number' => $value, 'name' => 'Example Member',
                ]]));
            }
            self::assertStringContainsString('/api/employees/77', $url);
            self::assertSame('GET', $method);
            return new MockResponse((string) json_encode(['data' => $this->employee($value)]));
        });
        $gateway = $this->gateway($http, 'employee');
        self::assertTrue($gateway->contact(20)['editable']);
        $gateway->updateTaxNumber(20, '52998224725');
        self::assertSame(1, $puts);
    }

    public function testMissingSalaryTypeDoesNotBlockContactTaxNumberUpdate(): void
    {
        $value = '00000000000';
        $puts = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$value, &$puts): MockResponse {
            if ($method === 'PUT') {
                self::assertStringContainsString('/api/contacts/20', $url);
                parse_str((string) $options['body'], $body);
                self::assertArrayNotHasKey('salary_type', $body);
                self::assertArrayNotHasKey('amount', $body);
                ++$puts;
                $value = $body['tax_number'];
                return new MockResponse('{}');
            }
            self::assertSame('GET', $method);
            return new MockResponse((string) json_encode(['data' => $this->employee($value, salaryType: null)]));
        });
        $gateway = $this->gateway($http, 'employee');
        self::assertTrue($gateway->contact(20)['editable']);
        $gateway->updateTaxNumber(20, '52998224725');
        self::assertSame(1, $puts);
    }

    public function testWrongCompanyIsRejectedBeforeAnyHttpCall(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('Wrong-company record must not reach HTTP.');
        });
        $this->expectException(DomainException::class);
        $this->gateway($http, 'vendor', 2)->contact(20);
    }

    public function testWrongEmployeeContactIsRejectedBeforeMutation(): void
    {
        $http = new MockHttpClient(function (string $method): MockResponse {
            self::assertSame('GET', $method);
            return new MockResponse((string) json_encode(['data' => $this->employee(contactId: 42)]));
        });
        $this->expectException(DomainException::class);
        $this->gateway($http, 'employee')->updateTaxNumber(20, '52998224725');
    }

    public function testAmbiguousEmployeeMappingIsRejectedBeforeHttpCall(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('Ambiguous employee mapping must not reach HTTP.');
        });
        $this->expectException(DomainException::class);
        $this->gateway($http, 'employee', employeeIds: [77, 78])->contact(20);
    }

    public function testVendorWithoutCurrencyIsNotUpdated(): void
    {
        $puts = 0;
        $http = new MockHttpClient(function (string $method) use (&$puts): MockResponse {
            if ($method === 'PUT') {
                $puts++;
            }
            return new MockResponse((string) json_encode(['data' => $this->vendor()]));
        });
        $gateway = $this->gateway($http, currency: null);
        self::assertFalse($gateway->contact(20)['editable']);
        try {
            $gateway->updateTaxNumber(20, '52998224725');
            self::fail('Expected currency validation failure.');
        } catch (DomainException) {
            self::assertSame(0, $puts);
        }
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        return $params;
    }
}
