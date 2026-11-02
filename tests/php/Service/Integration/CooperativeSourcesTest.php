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

    public function testUnlinkedNextcloudAccountIsOnlySuggestedWithoutMatchingTaxNumber(): void
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
        self::assertSame([
            'exists' => true,
            'uid' => 'member-b',
            'values' => ['tax_number' => 'reference-other', 'dependents' => 1],
        ], $sources->nextcloudForMember([
            'id' => 2,
            'username' => 'member-b',
            'preferences' => ['tax_number' => 'reference-original'],
        ]));
    }

}
