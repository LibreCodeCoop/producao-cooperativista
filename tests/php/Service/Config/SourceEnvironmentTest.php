<?php

declare(strict_types=1);

namespace App\Tests\Service\Config;

use App\Service\Config\SourceEnvironment;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SourceEnvironmentTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $previous = [];
    /** @var array<string,mixed> */
    private array $previousEnv = [];
    /** @var array<string,mixed> */
    private array $previousServer = [];

    private const array KEYS = [
        'APP_ENV', 'APP_DEPLOYMENT_STAGE', 'APP_DATA_SOURCE_PROFILE',
        'KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN',
        'AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER',
        'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID', 'DATABASE_AKAUNTING_URL',
        'PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER', 'PROFILE_FIELDS_AUTH_TOKEN',
    ];

    protected function setUp(): void
    {
        foreach ([...self::KEYS, ...array_map(
            static fn (string $key): string => 'SOURCE_PRODUCTION_' . $key,
            array_slice(self::KEYS, 3),
        )] as $key) {
            $this->previous[$key] = getenv($key);
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
            $this->previousServer[$key] = $_SERVER[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
            if ($this->previousEnv[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->previousEnv[$key];
            }
            if ($this->previousServer[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->previousServer[$key];
            }
        }
    }

    public function testProductionNeverAcceptsStagingAsDataSource(): void
    {
        putenv('APP_DEPLOYMENT_STAGE=production');
        putenv('APP_DATA_SOURCE_PROFILE=staging');
        self::assertSame('production', SourceEnvironment::stage());
        self::assertSame('production', SourceEnvironment::selectedProfile());
        SourceEnvironment::apply();
        SourceEnvironment::assertWritable();
    }

    public function testLocalProductionProfileIsReadOnlyAndRequiresExplicitAliases(): void
    {
        putenv('APP_DEPLOYMENT_STAGE=development');
        putenv('APP_DATA_SOURCE_PROFILE=production');
        self::assertSame('development', SourceEnvironment::stage());
        $this->expectException(DomainException::class);
        SourceEnvironment::assertWritable();
    }

    public function testIncompleteProfileCannotOverrideConfiguredSources(): void
    {
        putenv('APP_DEPLOYMENT_STAGE=staging');
        putenv('APP_DATA_SOURCE_PROFILE=production');
        putenv('SOURCE_PRODUCTION_KIMAI_API_BASE_URL');
        putenv('KIMAI_API_BASE_URL=https://staging.example.test/api');
        self::assertFalse(SourceEnvironment::productionReady());
        try {
            SourceEnvironment::apply();
            self::fail('Missing production source credentials were accepted.');
        } catch (DomainException) {
            self::assertSame('https://staging.example.test/api', getenv('KIMAI_API_BASE_URL'));
        }
    }

    public function testCompletedProfileCanReplaceOnlyKnownSourceVariables(): void
    {
        putenv('APP_DEPLOYMENT_STAGE=staging');
        putenv('APP_DATA_SOURCE_PROFILE=production');
        foreach (array_slice(self::KEYS, 3) as $key) {
            putenv('SOURCE_PRODUCTION_' . $key . '=safe-test-value');
            putenv($key . '=staging-value');
        }
        self::assertTrue(SourceEnvironment::productionReady());
        SourceEnvironment::apply();
        foreach (array_slice(self::KEYS, 3) as $key) {
            self::assertSame('safe-test-value', getenv($key));
        }
        self::assertSame('production', SourceEnvironment::selectedProfile());
    }

    public function testProductionFrameworkEnvOverridesUnsafeStageConfig(): void
    {
        putenv('APP_ENV=prod');
        putenv('APP_DEPLOYMENT_STAGE=development');
        putenv('APP_DATA_SOURCE_PROFILE=staging');
        self::assertSame('production', SourceEnvironment::stage());
        self::assertSame('production', SourceEnvironment::selectedProfile());
        SourceEnvironment::assertWritable();
    }

    public function testDefaultStageUsesFrameworkEnvironmentWithoutHostNames(): void
    {
        putenv('APP_DEPLOYMENT_STAGE');
        putenv('APP_ENV=dev');
        self::assertSame('development', SourceEnvironment::stage());
        putenv('APP_ENV=prod');
        self::assertSame('production', SourceEnvironment::stage());
    }
}
