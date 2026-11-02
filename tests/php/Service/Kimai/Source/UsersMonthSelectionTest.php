<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Entity\Producao\User;
use App\Service\Kimai\Source\Users;
use DomainException;
use InvalidArgumentException;
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

    public function testNoParticipantsProduceNoImportedUsers(): void
    {
        $source = $this->source();
        self::assertSame([], $source->setRequiredUserIds([])->getList());
        self::assertSame([], $source->processed);
    }
}
