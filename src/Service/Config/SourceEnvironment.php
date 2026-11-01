<?php

declare(strict_types=1);

namespace App\Service\Config;

use DomainException;

/**
 * Execution stage never changes through the web UI. Non-production installations
 * may read production source systems using an explicitly provisioned profile.
 */
final class SourceEnvironment
{
    private const array SOURCE_KEYS = [
        'KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN',
        'AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER',
        'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID',
        'DATABASE_AKAUNTING_URL',
        'PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER',
        'PROFILE_FIELDS_AUTH_TOKEN',
    ];

    public static function stage(): string
    {
        $configured = trim((string) getenv('APP_DEPLOYMENT_STAGE'));
        // APP_ENV=prod is authoritative: never allow a secondary setting
        // to make the production server appear writable as "staging".
        if (getenv('APP_ENV') === 'prod') {
            return 'production';
        }
        if ($configured === '') {
            return 'development';
        }
        if (!in_array($configured, ['production', 'staging', 'development'], true)) {
            throw new DomainException('APP_DEPLOYMENT_STAGE deve ser production, staging ou development.');
        }
        return $configured;
    }

    public static function selectedProfile(): string
    {
        if (self::stage() === 'production') {
            return 'production';
        }
        $profile = trim((string) getenv('APP_DATA_SOURCE_PROFILE'));
        if ($profile === '') {
            return 'staging';
        }
        if (!in_array($profile, ['staging', 'production'], true)) {
            throw new DomainException('Perfil de origem não permitido.');
        }
        return $profile;
    }

    public static function productionReady(): bool
    {
        return array_all(self::SOURCE_KEYS, fn ($key) => !(trim((string) getenv('SOURCE_PRODUCTION_' . $key)) === ''));
    }

    public static function assertWritable(): void
    {
        if (self::stage() !== 'production' && self::selectedProfile() === 'production') {
            throw new DomainException('A consulta às fontes de produção no ambiente de testes é somente leitura.');
        }
    }

    public static function apply(): void
    {
        if (self::stage() === 'production' || self::selectedProfile() === 'staging') {
            return;
        }
        if (!self::productionReady()) {
            throw new DomainException('Perfil de produção incompleto. Configure todas as conexões antes de selecioná-lo.');
        }
        foreach (self::SOURCE_KEYS as $key) {
            $value = (string) getenv('SOURCE_PRODUCTION_' . $key);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
