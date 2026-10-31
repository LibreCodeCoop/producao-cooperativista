<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Provider\Kimai;
use App\Service\Nextcloud\ProfileFieldsClient;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin live adapters for existing Kimai, Nextcloud and Akaunting credentials.
 * No profile data is persisted to the ETL database by this service.
 */
final class CooperativeSources
{
    use Kimai;

    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private ProfileFieldsClient $profileFields,
        private AkauntingContactGateway $akauntingContacts,
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $staging,
    ) {
    }

    protected function getKimaiHttpClient(): HttpClientInterface
    {
        return $this->http;
    }

    /** @return list<array{id: int, username: string, alias: string, enabled: bool, system: bool}> */
    public function users(): array
    {
        $result = [];
        foreach ($this->doRequestKimai('/users', ['visible' => 3]) as $raw) {
            if (!is_array($raw) || (int) ($raw['id'] ?? 0) <= 0 || empty($raw['username'])) {
                continue;
            }
            // Never pass Kimai's apiToken or other private API response data to Twig.
            $result[] = [
                'id' => (int) $raw['id'],
                'username' => (string) $raw['username'],
                'alias' => (string) (($raw['alias'] ?? '') ?: $raw['username']),
                'enabled' => (bool) ($raw['enabled'] ?? true),
                'system' => (bool) ($raw['systemAccount'] ?? false),
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcasecmp($a['alias'], $b['alias']));

        return $result;
    }

    /** @return array{id: int, username: string, alias: string, preferences: array<string, string>, enabled: bool, system: bool} */
    public function user(int $id): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('ID de cooperado inválido.');
        }
        $raw = $this->doRequestKimai('/users/' . $id);
        if ((int) ($raw['id'] ?? 0) !== $id || trim((string) ($raw['username'] ?? '')) === '') {
            throw new RuntimeException('Usuário não encontrado no Kimai.');
        }
        $preferences = [];
        foreach ($raw['preferences'] ?? [] as $preference) {
            if (is_array($preference)
                && in_array($preference['name'] ?? null, ['tax_number'], true)
                && is_scalar($preference['value'] ?? null)) {
                $preferences[$preference['name']] = (string) $preference['value'];
            }
        }

        return [
            'id' => $id,
            'username' => (string) $raw['username'],
            'alias' => (string) (($raw['alias'] ?? '') ?: $raw['username']),
            'preferences' => $preferences,
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'system' => (bool) ($raw['systemAccount'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function importedSnapshot(int $id): array
    {
        return $this->staging->fetchAssociative(
            'SELECT id, akaunting_contact_id, tax_number, peso, dependents FROM users WHERE id = :id',
            ['id' => $id]
        ) ?: [];
    }

    /** @return array<int, int> Seconds recorded in the ETL snapshot, not live timesheets. */
    public function monthlyHours(string $month): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$start || $start->format('Y-m') !== $month) {
            throw new \InvalidArgumentException('Competência inválida.');
        }
        $rows = $this->staging->fetchAllAssociative(
            'SELECT user_id, SUM(COALESCE(duration, 0)) AS seconds FROM timesheet
             WHERE begin >= :start AND begin < :end GROUP BY user_id',
            ['start' => $start->format('Y-m-d'), 'end' => $start->modify('first day of next month')->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = (int) $row['seconds'];
        }

        return $result;
    }

    /**
     * @return array<int, string> Last ETL snapshot, not live Nextcloud values.
     */
    public function importedWeights(): array
    {
        $result = [];
        foreach ($this->staging->fetchAllAssociative('SELECT id, peso FROM users WHERE peso IS NOT NULL') as $row) {
            if ((int) ($row['id'] ?? 0) > 0 && is_numeric($row['peso'] ?? null)) {
                $result[(int) $row['id']] = (string) $row['peso'];
            }
        }

        return $result;
    }

    /**
     * @param array{username: string, preferences: array<string, string>} $member
     * @return array{exists: bool, uid: string, values: array<string, mixed>}
     */
    public function nextcloudForMember(array $member): array
    {
        $uid = $member['username'];
        if (!$this->profileFields->isConfigured()) {
            return ['exists' => false, 'uid' => '', 'values' => []];
        }
        if (!$this->profileFields->userExists($uid)) {
            $uid = $this->profileFields->findUserUidByTaxNumber(
                (string) ($member['preferences']['tax_number'] ?? ''),
            ) ?? '';
        }
        $fields = $uid !== '' ? $this->profileFields->getFieldsForUser($uid) : [];
        if ($uid !== '') {
            $reference = trim((string) ($member['preferences']['tax_number'] ?? ''));
            $key = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
            $profileTax = trim((string) ($fields[$key] ?? ''));
            if ($reference === '' || $profileTax === '' || !hash_equals($reference, $profileTax)) {
                throw new \DomainException(
                    'Vínculo Kimai/Nextcloud não confirmado: CPF/CNPJ ausente ou divergente. '
                    . 'Corrija a identificação entre as contas antes de editar.'
                );
            }
        }
        return [
            'exists' => $uid !== '',
            'uid' => $uid,
            'values' => $fields,
        ];
    }

    public function updateNextcloud(string $uid, string $field, string|float|int $value): void
    {
        $this->profileFields->updateUserField($uid, $field, $value);
        $fresh = $this->profileFields->getFieldsForUser($uid);
        if ((string) ($fresh[$field] ?? '') !== (string) $value) {
            throw new RuntimeException('Nextcloud não confirmou o novo valor. Confira a origem antes de repetir.');
        }
    }

    public function updateKimai(int $id, string $field, string|int $value): void
    {
        if (!in_array($field, ['tax_number'], true)) {
            throw new \InvalidArgumentException('Campo Kimai não permitido.');
        }
        $this->user($id); // Recheck existence before attempting a write.
        $base = rtrim((string) getenv('KIMAI_API_BASE_URL'), '/');
        $token = (string) getenv('KIMAI_API_TOKEN');
        if ($base === '' || $token === '') {
            throw new RuntimeException('Integração Kimai indisponível.');
        }
        $response = $this->http->request('PATCH', $base . '/users/' . $id . '/preferences', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'json' => [['name' => $field, 'value' => (string) $value]],
            'timeout' => 20,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Kimai recusou a atualização de preferência (HTTP ' . $response->getStatusCode() . ').');
        }
        if ((string) ($this->user($id)['preferences'][$field] ?? '') !== (string) $value) {
            throw new RuntimeException('Kimai não confirmou o novo valor. Confira a origem antes de repetir.');
        }
    }

    /** @return array<string, mixed> */
    public function akauntingContact(int $id): array
    {
        return $this->akauntingContacts->contact($id);
    }

    public function updateAkauntingTaxNumber(int $contactId, string $newNumber): void
    {
        $this->akauntingContacts->updateTaxNumber($contactId, $newNumber);
    }
}
