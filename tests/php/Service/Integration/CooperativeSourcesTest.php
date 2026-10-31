<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\CooperativeSources;
use App\Service\Integration\AkauntingContactGateway;
use App\Provider\Akaunting\Request as AkauntingRequest;
use App\Service\Nextcloud\ProfileFieldsClient;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CooperativeSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('KIMAI_API_BASE_URL=https://kimai.example.org/api');
        putenv('KIMAI_API_TOKEN=secret-for-test');
    }

    protected function tearDown(): void
    {
        putenv('KIMAI_API_BASE_URL');
        putenv('KIMAI_API_TOKEN');
    }

    private function sources(MockHttpClient $http): CooperativeSources
    {
        $logger = new NullLogger();
        return new CooperativeSources(
            $http,
            $logger,
            new ProfileFieldsClient($http, $logger),
            new AkauntingContactGateway(
                new AkauntingRequest($logger, $http),
                $this->createStub(Connection::class),
            ),
            $this->createStub(Connection::class),
        );
    }

    public function testRosterNeverExposesKimaiTokensOrOtherPrivateAttributes(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
                ['id' => 2, 'username' => 'member-b', 'alias' => 'Bruna', 'apiToken' => 'sensitive'],
                ['id' => 1, 'username' => 'member-a', 'alias' => 'Ana', 'apiToken' => 'secret-value'],
            ])));
        $roster = $this->sources($http)->users();
        self::assertSame([1, 2], array_column($roster, 'id'));
        self::assertSame('Ana', $roster[0]['alias']);
        self::assertArrayNotHasKey('apiToken', $roster[0]);
    }

    public function testIndividualKimaiLookupPreservesServiceAccountFlag(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'id' => 2, 'username' => 'service-bot', 'systemAccount' => true,
        ])));
        self::assertTrue($this->sources($http)->user(2)['system']);
    }

    public function testImportedWeightsUseOnlyLocalSnapshotAndPreservePrecision(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('Rendering the imported snapshot must not make an external HTTP request.');
        });
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')
            ->with('SELECT id, peso FROM users WHERE peso IS NOT NULL')
            ->willReturn([
                ['id' => 2, 'peso' => '1.2500'],
                ['id' => 3, 'peso' => null],
                ['id' => 4, 'peso' => '2.75'],
                ['id' => 0, 'peso' => '3'],
                ['id' => 5, 'peso' => 'invalid'],
            ]);
        $logger = new NullLogger();
        $sources = new CooperativeSources(
            $http,
            $logger,
            new ProfileFieldsClient($http, $logger),
            new AkauntingContactGateway(
                new AkauntingRequest($logger, $http),
                $this->createStub(Connection::class),
            ),
            $db,
        );

        self::assertSame([2 => '1.2500', 4 => '2.75'], $sources->importedWeights());
    }

    public function testUpdatesKimaiOnlyApprovedPreferenceAndConfirmsReadback(): void
    {
        $updated = false;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$updated): MockResponse {
            if ($method === 'PATCH') {
                self::assertStringEndsWith('/users/2/preferences', $url);
                self::assertSame([['name' => 'tax_number', 'value' => '52998224725']], json_decode($options['body'], true));
                $updated = true;
                return new MockResponse('{}');
            }
            self::assertSame('GET', $method);
            return new MockResponse((string) json_encode([
                'id' => 2, 'username' => 'member-b', 'alias' => 'Bruna',
                'apiToken' => 'not-for-twig',
                'preferences' => [
                    ['name' => 'tax_number', 'value' => $updated ? '52998224725' : ''],
                    ['name' => 'dependents', 'value' => '7'],
                ],
            ]));
        });
        $sources = $this->sources($http);
        $sources->updateKimai(2, 'tax_number', '52998224725');
        self::assertFalse($sources->user(2)['system']);
        self::assertSame('52998224725', $sources->user(2)['preferences']['tax_number']);
        self::assertArrayNotHasKey('dependents', $sources->user(2)['preferences']);
    }

    public function testNextcloudProfileMustMatchKimaiTaxReference(): void
    {
        $logger = new NullLogger();
        $client = $this->createMock(ProfileFieldsClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->expects(self::once())->method('userExists')->with('member-b')->willReturn(true);
        $client->expects(self::once())->method('getFieldsForUser')->with('member-b')->willReturn([
            'tax_number' => 'reference-other', 'dependents' => 1,
        ]);
        $sources = new CooperativeSources(
            new MockHttpClient([]),
            $logger,
            $client,
            new AkauntingContactGateway(
                new AkauntingRequest($logger, new MockHttpClient([])),
                $this->createStub(Connection::class),
            ),
            $this->createStub(Connection::class),
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Vínculo Kimai/Nextcloud não confirmado');
        $sources->nextcloudForMember([
            'username' => 'member-b', 'preferences' => ['tax_number' => 'reference-original'],
        ]);
    }

    public function testKimaiDependentsCannotBeUpdated(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('Dependents must never reach the Kimai API.');
        });
        $this->expectException(\InvalidArgumentException::class);
        $this->sources($http)->updateKimai(2, 'dependents', 3);
    }

    public function testUnsupportedKimaiFieldIsRejectedBeforeCallingApi(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('Unexpected write attempt.');
        });
        $this->expectException(\InvalidArgumentException::class);
        $this->sources($http)->updateKimai(2, 'hourly_rate', '500');
    }

}
