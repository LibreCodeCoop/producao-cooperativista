<?php

declare(strict_types=1);

namespace App\Tests\Service\Kimai\Source;

use App\Service\Kimai\Source\Users;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class UsersAkauntingMatchTest extends TestCase
{
    private Connection $database;
    private Users $source;
    private string|false $previousCompany;

    protected function setUp(): void
    {
        $this->previousCompany = getenv('AKAUNTING_COMPANY_ID');
        putenv('AKAUNTING_COMPANY_ID=1');
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement(
            'CREATE TABLE contacts (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, '
            . 'tax_number VARCHAR(20), email VARCHAR(128), type VARCHAR(32), deleted_at DATETIME NULL)'
        );
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->database);
        $this->source = new ReflectionClass(Users::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(Users::class, 'entityManagerAkaunting')->setValue($this->source, $manager);
    }

    protected function tearDown(): void
    {
        putenv($this->previousCompany === false
            ? 'AKAUNTING_COMPANY_ID'
            : 'AKAUNTING_COMPANY_ID=' . $this->previousCompany);
        $this->database->close();
    }

    private function contact(int $id, int $company, string $taxNumber, string $email, string $type = 'vendor'): void
    {
        $this->database->insert('contacts', [
            'id' => $id,
            'company_id' => $company,
            'tax_number' => $taxNumber,
            'email' => $email,
            'type' => $type,
        ]);
    }

    private function matchedId(string $taxNumber, string $email = 'shared@example.test'): ?int
    {
        $match = new ReflectionMethod(Users::class, 'updateWithAkauntingData');
        $row = $match->invoke($this->source, [
            'tax_number' => $taxNumber,
            'email' => $email,
            'kimai_username' => $email,
        ]);

        return $row['akaunting_contact_id'] ?? null;
    }

    public function testMatchUsesExactTaxNumberAndCompanyNotEmail(): void
    {
        $this->contact(10, 1, 'different', 'shared@example.test');
        $this->contact(11, 2, 'correct', 'shared@example.test');
        $this->contact(12, 1, 'correct', 'other@example.test', 'employee');

        self::assertSame(12, $this->matchedId('correct'));
    }

    public function testAnotherCompanyOrMatchingEmailIsNotEnough(): void
    {
        $this->contact(11, 2, 'correct', 'shared@example.test');
        $this->contact(12, 1, 'different', 'shared@example.test');

        self::assertNull($this->matchedId('correct'));
    }

    public function testAmbiguousTaxNumberNeverSelectsArbitraryContact(): void
    {
        $this->contact(10, 1, 'duplicate', 'one@example.test');
        $this->contact(12, 1, 'duplicate', 'two@example.test', 'employee');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mais de um contato financeiro');
        $this->matchedId('duplicate');
    }
}
