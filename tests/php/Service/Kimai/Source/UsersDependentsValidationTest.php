<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Service\Kimai\Source\Users;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class UsersDependentsValidationTest extends TestCase
{
    #[DataProvider('invalidCounts')]
    public function testMissingOrInvalidDependentCountBlocksImport(mixed $count): void
    {
        $source = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod(Users::class, 'validate');
        $user = ['tax_number' => 'synthetic-id'];
        if ($count !== null) {
            $user['dependents'] = $count;
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Profile Fields');
        $validate->invoke($source, $user);
    }

    public static function invalidCounts(): array
    {
        return [
            'absent' => [null],
            'negative' => [-1],
            'fractional' => [2.5],
            'legacy string' => ['3'],
        ];
    }

    public function testKimaiAccountWithoutTaxNumberIsSkippedBeforeRequiringProfile(): void
    {
        $source = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod(Users::class, 'validate');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sem CPF');
        $validate->invoke($source, []);
    }

    public function testExplicitZeroFromNextcloudIsValid(): void
    {
        $source = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod(Users::class, 'validate');
        $validate->invoke($source, ['tax_number' => 'synthetic-id', 'dependents' => 0]);
        self::assertTrue(true);
    }
}
