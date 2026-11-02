<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Service\Kimai\Source\Users;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UsersNativeProfileTest extends TestCase
{
    public function testKimaiNativeProfileIgnoresAllCustomPluginPreferences(): void
    {
        $source = new class () extends Users {
            public function __construct()
            {
            }

            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                \PHPUnit\Framework\Assert::assertSame('/users/17', $endpoint);
                return [
                    'id' => 17,
                    'email' => 'native@example.test',
                    'preferences' => [
                        ['name' => 'tax_number', 'value' => 'obsolete-fiscal-copy'],
                        ['name' => 'dependents', 'value' => '99'],
                        ['name' => 'email', 'value' => 'obsolete@example.test'],
                    ],
                ];
            }
        };
        $normalized = new ReflectionMethod(Users::class, 'updateFromKimaiUser')->invoke($source, [
            'id' => 17, 'username' => 'native-user', 'alias' => 'Pessoa',
            'tax_number' => 'untrusted', 'dependents' => 99, 'akaunting_contact_id' => 987,
        ]);
        self::assertSame('native@example.test', $normalized['email']);
        self::assertSame('native-user', $normalized['kimai_username']);
        self::assertArrayNotHasKey('tax_number', $normalized);
        self::assertArrayNotHasKey('dependents', $normalized);
        self::assertArrayNotHasKey('akaunting_contact_id', $normalized);
    }
}
