<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Entity\Producao\User;
use App\Service\Kimai\Source\Users;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class UsersMonthSelectionTest extends TestCase
{
    private function source(): Users
    {
        $source = new class () extends Users {
            /** @var list<int> */
            public array $processed = [];

            public function __construct()
            {
            }

            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                return [
                    ['id' => 30, 'username' => 'unrelated'],
                    ['id' => 5, 'username' => 'participant'],
                    ['id' => 1, 'username' => 'participant2'],
                ];
            }

            public function fromArray(array $array): User
            {
                $id = (int) $array['id'];
                $this->processed[] = $id;
                if ($id === 30) {
                    throw new InvalidArgumentException('Missing tax number');
                }
                return new User();
            }
        };
        new ReflectionProperty(Users::class, 'logger')->setValue($source, new NullLogger());
        return $source;
    }

    public function testUnrelatedKimaiAccountDoesNotBlockMonthlyImport(): void
    {
        $source = $this->source();
        $source->setRequiredUserIds([1, 5])->getList();
        self::assertSame([5, 1], $source->processed);
    }

    public function testReferencedUserMissingFromRosterIsNeverSilentlySkipped(): void
    {
        $source = $this->source();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Kimai #19');
        $source->setRequiredUserIds([19])->getList();
    }

    public function testReferencedUserWithInvalidFiscalDataCannotBeSilentlyIgnored(): void
    {
        $source = $this->source();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PROFILE_SOURCE');
        $source->setRequiredUserIds([30])->getList();
    }

    #[DataProvider('profileErrors')]
    public function testParticipantFailureNamesKimaiAliasAndExplainsPreciseCause(
        string $cause,
        string $expectedCode,
    ): void {
        $source = new class ($cause) extends Users {
            public function __construct(private string $cause)
            {
            }

            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                return [['id' => 40, 'username' => 'luis.souza', 'alias' => 'Luis Souza']];
            }

            public function fromArray(array $array): User
            {
                if (str_contains($this->cause, 'Cooperado sem CPF')) {
                    throw new InvalidArgumentException($this->cause);
                }
                throw new DomainException($this->cause);
            }
        };
        new ReflectionProperty(Users::class, 'logger')->setValue($source, new NullLogger());
        try {
            $source->setRequiredUserIds([40])->getList();
            self::fail('The incorrect source must not be imported.');
        } catch (DomainException $error) {
            self::assertStringContainsString(
                $expectedCode . ': usuário Kimai #40 (Luis Souza)',
                $error->getMessage()
            );
            self::assertStringNotContainsString('private-test-token', $error->getMessage());
            self::assertSame($cause, $error->getPrevious()?->getMessage());
        }
    }

    /** @return iterable<string, array{string,string}> */
    public static function profileErrors(): iterable
    {
        yield 'missing Nextcloud link' => [
            'PROFILE_SOURCE: cooperado participante sem kimai_user_id explícito no Profile Fields; private-test-token',
            'PROFILE_KIMAI_LINK',
        ];
        yield 'missing financial contact' => [
            'PROFILE_SOURCE: contato financeiro não vinculado no Nextcloud Profile Fields; private-test-token',
            'PROFILE_ACCOUNTING_LINK',
        ];
        yield 'invalid financial contact' => [
            'Contato financeiro vinculado inexistente ou de outra empresa no Akaunting; private-test-token',
            'PROFILE_ACCOUNTING_INVALID',
        ];
        yield 'tax mismatch' => [
            'CPF/CNPJ diverge entre o Profile Fields e o contato financeiro; private-test-token',
            'PROFILE_TAX_MISMATCH',
        ];
        yield 'missing dependents' => [
            'Dependentes ausentes ou inválidos no Nextcloud Profile Fields; private-test-token',
            'PROFILE_DEPENDENTS',
        ];
        yield 'missing tax' => [
            'Cooperado sem CPF encontrado; private-test-token',
            'PROFILE_TAX_MISSING',
        ];
    }

    public function testUnsafeKimaiAliasNeverGoesToBrowserError(): void
    {
        $source = new class () extends Users {
            public function __construct()
            {
            }
            public function doRequestKimai(string $endpoint, array $query = [], ?callable $parseFunction = null): array
            {
                return [['id' => 40, 'username' => 'external', 'alias' => 'private@example.test']];
            }
            public function fromArray(array $array): User
            {
                throw new DomainException('PROFILE_SOURCE: kimai_user_id explícito ausente.');
            }
        };
        new ReflectionProperty(Users::class, 'logger')->setValue($source, new NullLogger());
        try {
            $source->setRequiredUserIds([40])->getList();
            self::fail('Expected missing link.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Kimai #40', $error->getMessage());
            self::assertStringNotContainsString('private@example.test', $error->getMessage());
        }
    }

    public function testNoParticipantsProduceNoImportedUsers(): void
    {
        $source = $this->source();
        self::assertSame([], $source->setRequiredUserIds([])->getList());
        self::assertSame([], $source->processed);
    }
}
