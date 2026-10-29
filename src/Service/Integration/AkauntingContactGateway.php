<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Provider\Akaunting\Request as AkauntingRequest;
use Doctrine\DBAL\Connection;
use DomainException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only DB lookup resolves the source record identity; all changes
 * go through Akaunting's authenticated HTTP API and are read back.
 */
final class AkauntingContactGateway
{
    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(
        private AkauntingRequest $api,
        #[Autowire(service: 'doctrine.dbal.akaunting_connection')]
        private Connection $akauntingDb,
    ) {
    }

    /** @return array{id: int, company_id: int, type: string, name: string, tax_number: string, email: string, editable: bool, reason: string} */
    public function contact(int $contactId): array
    {
        $identity = $this->identity($contactId);
        if ($identity['type'] === 'vendor') {
            $vendor = $this->vendor($contactId, $identity['company_id']);
            $reason = (string) ($identity['currency_code'] ?? '') === ''
                ? 'Fornecedor sem moeda cadastrada no Akaunting. Corrija o cadastro antes de editar o CPF/CNPJ.'
                : '';

            return $this->present($vendor, $reason === '', $reason);
        }

        $employee = $this->employee(
            $this->employeeId($contactId, $identity['company_id']),
            $contactId,
            $identity['company_id'],
        );
        $reason = $this->employeeUpdateBlocker($employee);

        return $this->present($employee['contact'], $reason === '', $reason);
    }

    public function updateTaxNumber(int $contactId, string $taxNumber): void
    {
        $identity = $this->identity($contactId);
        $contact = $this->contact($contactId);

        if (!$contact['editable']) {
            throw new DomainException($contact['reason']);
        }

        $body = $this->preservedContactFields($identity, $contact, $taxNumber);

        if ($identity['type'] === 'vendor') {
            $this->api->send(
                '/api/contacts/' . $contactId,
                $body,
                ['search' => 'type:vendor'],
                'PUT',
            );
        } else {
            $employeeId = $this->employeeId($contactId, $identity['company_id']);
            $employee = $this->employee($employeeId, $contactId, $identity['company_id']);
            $blocker = $this->employeeUpdateBlocker($employee);
            if ($blocker !== '') {
                throw new DomainException($blocker);
            }

            $body['birth_day'] = $employee['birth_day'];
            $body['gender'] = $employee['gender'];
            $body['department_id'] = (int) $employee['department']['id'];
            $body['amount'] = $employee['salary'];
            $body['salary_type'] = $employee['salary_type'];
            $body['hired_at'] = $employee['hired_at'];

            // /api/employees/{id} uses the Employees module's own ACL and
            // maps employee IDs to their linked financial contacts.
            $this->api->send('/api/employees/' . $employeeId, $body, method: 'PUT');
        }

        if ($this->contact($contactId)['tax_number'] !== $taxNumber) {
            throw new RuntimeException('Akaunting não confirmou a atualização do CPF/CNPJ. Confira a origem antes de tentar novamente.');
        }
    }

    /** @return array<string, mixed> */
    private function identity(int $contactId): array
    {
        if ($contactId <= 0) {
            throw new DomainException('Contato financeiro não vinculado à importação.');
        }
        $company = $this->companyId();
        $row = $this->akauntingDb->fetchAssociative(
            'SELECT id, company_id, type, name, email, phone, address, city, state, zip_code, country, currency_code
             FROM contacts WHERE id = :id AND company_id = :company AND deleted_at IS NULL',
            ['id' => $contactId, 'company' => $company],
        );
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $contactId
            || (int) ($row['company_id'] ?? 0) !== $company
            || !in_array($row['type'] ?? null, ['vendor', 'employee'], true)) {
            throw new DomainException('Contato inexistente, de outra empresa ou com tipo não permitido.');
        }

        return $row;
    }

    private function companyId(): int
    {
        $company = (int) getenv('AKAUNTING_COMPANY_ID');
        if ($company <= 0) {
            throw new RuntimeException('Empresa Akaunting não configurada na integração.');
        }

        return $company;
    }

    private function employeeId(int $contactId, int $company): int
    {
        $ids = $this->akauntingDb->fetchFirstColumn(
            'SELECT id FROM employees_employees
             WHERE contact_id = :contact AND company_id = :company
               AND deleted_at IS NULL AND dismissed = 0',
            ['contact' => $contactId, 'company' => $company],
        );
        if (count($ids) !== 1 || (int) $ids[0] <= 0) {
            throw new DomainException(
                'Contato de funcionário sem vínculo ativo único no módulo Employees do Akaunting. Revise os vínculos antes de editar.',
            );
        }

        return (int) $ids[0];
    }

    /** @return array<string, mixed> */
    private function vendor(int $contactId, int $company): array
    {
        $data = $this->api->send(
            '/api/contacts/' . $contactId,
            query: ['search' => 'type:vendor'],
            method: 'GET',
        )['data'] ?? null;

        $this->assertContact($data, $contactId, $company, 'vendor');

        return $data;
    }

    /** @return array<string, mixed> */
    private function employee(int $employeeId, int $contactId, int $company): array
    {
        $data = $this->api->send('/api/employees/' . $employeeId, method: 'GET')['data'] ?? null;
        if (!is_array($data) || (int) ($data['id'] ?? 0) !== $employeeId
            || (int) ($data['company_id'] ?? 0) !== $company) {
            throw new DomainException('API Employees retornou registro de funcionário incompatível com a empresa.');
        }
        $this->assertContact($data['contact'] ?? null, $contactId, $company, 'employee');

        return $data;
    }

    private function assertContact(mixed $data, int $contactId, int $company, string $type): void
    {
        if (!is_array($data) || (int) ($data['id'] ?? 0) !== $contactId
            || (int) ($data['company_id'] ?? 0) !== $company
            || ($data['type'] ?? null) !== $type) {
            throw new DomainException('API Akaunting retornou contato com identidade, empresa ou tipo incompatível.');
        }
    }

    /** @param array<string, mixed> $data
     *  @return array{id: int, company_id: int, type: string, name: string, tax_number: string, email: string, editable: bool, reason: string}
     */
    private function present(array $data, bool $editable, string $reason = ''): array
    {
        return [
            'id' => (int) $data['id'],
            'company_id' => (int) $data['company_id'],
            'type' => (string) $data['type'],
            'name' => (string) ($data['name'] ?? ''),
            'tax_number' => (string) ($data['tax_number'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'editable' => $editable,
            'reason' => $reason,
        ];
    }

    /** @param array<string, mixed> $employee */
    private function employeeUpdateBlocker(array $employee): string
    {
        if (!is_string($employee['salary_type'] ?? null) || trim($employee['salary_type']) === '') {
            return 'O cadastro de funcionário no Akaunting não possui tipo de remuneração. Configure o campo no módulo Employees antes de editar o CPF/CNPJ.';
        }
        if (!is_numeric($employee['salary'] ?? null)
            || !is_string($employee['gender'] ?? null) || $employee['gender'] === ''
            || (int) ($employee['department']['id'] ?? 0) <= 0
            || !is_string($employee['birth_day'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $employee['birth_day']) !== 1
            || !is_string($employee['hired_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $employee['hired_at']) !== 1) {
            return 'Cadastro de funcionário incompleto no Akaunting (datas, departamento, gênero ou remuneração). Corrija os dados na origem antes de editar o CPF/CNPJ.';
        }

        return '';
    }

    /**
     * Keep existing contact details unchanged. The upstream job copies these
     * fields into recurring documents when they exist.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    private function preservedContactFields(array $identity, array $contact, string $taxNumber): array
    {
        $body = [
            'company_id' => $contact['company_id'],
            'type' => $contact['type'],
            'name' => $contact['name'],
            'tax_number' => $taxNumber,
        ];
        foreach (['email', 'phone', 'address', 'city', 'state', 'zip_code', 'country'] as $field) {
            $body[$field] = $field === 'email'
                ? $contact['email']
                : ($identity[$field] ?? '');
        }
        if ($contact['type'] === 'vendor') {
            $currency = (string) ($identity['currency_code'] ?? '');
            if ($currency === '') {
                throw new DomainException('Fornecedor sem moeda cadastrada no Akaunting. Corrija o cadastro antes de editar o CPF/CNPJ.');
            }
            $body['currency_code'] = $currency;
        }

        return $body;
    }
}
