<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use App\Service\Kimai\Source\BasicUsers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** API boundary and real ETL storage, without mocking importer internals. */
final class BasicKimaiUsersIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string|false $oldUrl;
    private string|false $oldToken;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->oldUrl = getenv('KIMAI_API_BASE_URL');
        $this->oldToken = getenv('KIMAI_API_TOKEN');
        putenv('KIMAI_API_BASE_URL=https://kimai.example.test/api');
        putenv('KIMAI_API_TOKEN=not-a-live-token');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        foreach (['KIMAI_API_BASE_URL' => $this->oldUrl, 'KIMAI_API_TOKEN' => $this->oldToken] as $key => $old) {
            if ($old === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $old);
            }
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = 998810');
        $this->em->clear();
    }

    private function import(array $users): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($users): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://kimai.example.test/api/users', strtok($url, '?'));
            self::assertStringContainsString('size=50', $url);
            self::assertStringContainsString('Bearer ', implode(' ', $options['headers'] ?? []));
            return new MockResponse(json_encode($users, JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['X-Total-Pages: 1'],
            ]);
        });
        $source = new BasicUsers(
            $this->em,
            new NullLogger(),
            new BatchPersistence(),
            new ExistingEntityIndex(),
            $http
        );
        $source->saveList();
    }

    public function testKimaiUserCanBeImportedAndUpdatedWithoutCpfNextcloudOrAkaunting(): void
    {
        $this->import([[
            'id' => 998810, 'username' => 'worker',
            'alias' => 'Profissional de saúde', 'enabled' => true,
        ]]);

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM users WHERE id = 998810');
        self::assertIsArray($row);
        self::assertSame('Profissional de saúde', $row['alias']);
        self::assertSame('worker', $row['kimai_username']);
        self::assertNull($row['tax_number']);
        self::assertNull($row['peso']);
        self::assertNull($row['akaunting_contact_id']);
        self::assertSame(0, (int) $row['dependents']);

        $this->import([[
            'id' => 998810, 'username' => 'worker',
            'alias' => 'Profissional atualizado', 'enabled' => true,
        ]]);
        self::assertSame(
            'Profissional atualizado',
            $this->em->getConnection()->fetchOne('SELECT alias FROM users WHERE id = 998810')
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM users WHERE id = 998810'));
    }

    public function testUnknownSourceIdentityPreventsPartialWrites(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identificador');
        try {
            $this->import([
                ['id' => 998810, 'username' => 'good', 'alias' => 'Profissional A'],
                ['id' => 0, 'username' => 'bad', 'alias' => 'Profissional B'],
            ]);
        } finally {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM users WHERE id = 998810'));
        }
    }
}
