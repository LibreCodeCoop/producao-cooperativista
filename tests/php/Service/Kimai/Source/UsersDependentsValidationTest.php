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
            'numeric string' => ['3'],
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

    public function testImportIdentifiesOnlyTheKimaiIdOfInvalidDependentRecord(): void
    {
        $source = new class () extends Users {
            public function __construct()
            {
            }
            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                return [['id' => 17, 'username' => 'synthetic']];
            }
            public function fromArray(array $array): \App\Entity\Producao\User
            {
                throw new \DomainException('Dependentes ausentes ou inválidos no Nextcloud Profile Fields.');
            }
        };
        new ReflectionClass(Users::class)->getProperty('logger')
            ->setValue($source, new \Psr\Log\NullLogger());
        try {
            $source->getList();
            self::fail('Missing dependents must be reported.');
        } catch (DomainException $error) {
            self::assertStringContainsString('PROFILE_DEPENDENTS', $error->getMessage());
            self::assertStringContainsString('Kimai #17', $error->getMessage());
            self::assertStringNotContainsString('synthetic', $error->getMessage());
        }
    }

    public function testExplicitZeroFromNextcloudIsValid(): void
    {
        $source = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod(Users::class, 'validate');
        $validate->invoke($source, ['tax_number' => 'synthetic-id', 'dependents' => 0]);
        self::assertTrue(true);
    }
}
