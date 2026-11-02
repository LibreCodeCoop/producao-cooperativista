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

    private function contact(int $id, int $company, string $taxNumber, string $type = 'vendor'): void
    {
        $this->database->insert('contacts', [
            'id' => $id, 'company_id' => $company, 'tax_number' => $taxNumber,
            'email' => 'matching@example.test', 'type' => $type,
        ]);
    }

    private function enrich(array $row): array
    {
        return new ReflectionMethod(Users::class, 'updateWithAkauntingData')->invoke($this->source, $row);
    }

    public function testExplicitFinancialIdSelectsExactContactEvenIfCpfIsShared(): void
    {
        $this->source->setRequiredUserIds([22]);
        $this->contact(17, 1, 'reference');
        $this->contact(19, 1, 'reference', 'employee');
        self::assertSame(17, $this->enrich([
            'tax_number' => 'reference', 'akaunting_contact_id' => 17,
        ])['akaunting_contact_id']);
    }

    public function testNoCpfBasedContactDiscoveryEvenWhenOnlyOneMatchExists(): void
    {
        $this->contact(17, 1, 'reference');
        $result = $this->enrich(['tax_number' => 'reference', 'email' => 'matching@example.test']);
        self::assertArrayNotHasKey('akaunting_contact_id', $result);
    }

    public function testMonthlyParticipantWithoutFinancialLinkIsRejected(): void
    {
        $this->source->setRequiredUserIds([22]);
        $this->contact(17, 1, 'reference');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('akaunting_contact_id');
        $this->enrich(['tax_number' => 'reference']);
    }

    public function testLinkedContactFromWrongCompanyIsRejected(): void
    {
        $this->source->setRequiredUserIds([22]);
        $this->contact(17, 2, 'reference');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('outra empresa');
        $this->enrich(['tax_number' => 'reference', 'akaunting_contact_id' => 17]);
    }

    public function testLinkedContactFiscalDivergenceIsRejected(): void
    {
        $this->source->setRequiredUserIds([22]);
        $this->contact(17, 1, 'different');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CPF/CNPJ diverge');
        $this->enrich(['tax_number' => 'reference', 'akaunting_contact_id' => 17]);
    }
}
