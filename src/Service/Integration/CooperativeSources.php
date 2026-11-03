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

    /** @return array{id: int, username: string, alias: string, enabled: bool, system: bool} */
    public function user(int $id): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('ID de cooperado inválido.');
        }
        $raw = $this->doRequestKimai('/users/' . $id);
        if ((int) ($raw['id'] ?? 0) !== $id || trim((string) ($raw['username'] ?? '')) === '') {
            throw new RuntimeException('Usuário não encontrado no Kimai.');
        }
        return [
            'id' => $id,
            'username' => (string) $raw['username'],
            'alias' => (string) (($raw['alias'] ?? '') ?: $raw['username']),
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'system' => (bool) ($raw['systemAccount'] ?? false),
        ];
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
     * @param array{id: int, username: string} $member
     * @return array{exists: bool, uid: string, values: array<string, mixed>}
     */
    public function nextcloudForMember(array $member): array
    {
        if (!$this->profileFields->isConfigured()) {
            return ['exists' => false, 'uid' => '', 'values' => []];
        }
        $linked = $this->profileFields->findByKimaiId((int) $member['id']);
        if ($linked !== null) {
            return ['exists' => true, 'uid' => $linked['uid'], 'values' => $linked['values']];
        }
        // Before an explicit link is saved, the exact matching account UID is a
        // candidate for administration, not an automatic identity binding.
        $uid = trim((string) $member['username']);
        if ($uid === '' || !$this->profileFields->userExists($uid)) {
            return ['exists' => false, 'uid' => '', 'values' => []];
        }
        $fields = $this->profileFields->getFieldsForUser($uid);
        $linkedId = trim((string) ($fields['kimai_user_id'] ?? ''));
        if ($linkedId !== '' && $linkedId !== (string) $member['id']) {
            throw new \DomainException('A conta Nextcloud já está vinculada a outro usuário Kimai.');
        }

        return ['exists' => true, 'uid' => $uid, 'values' => $fields];
    }

    /** @return list<string> */
    public function searchNextcloudUsers(string $query): array
    {
        return $this->profileFields->searchUserUids($query);
    }

    /** @return list<array{id:int,label:string}> */
    public function searchAkauntingContacts(string $query): array
    {
        return $this->akauntingContacts->search($query);
    }

    /**
     * @return array{status:string,uid:string,name:string,tax_hint:string,contacts:list<array{id:int,name:string,type:string}>}
     */
    public function contactProvisioning(int $kimaiId): array
    {
        $member = $this->user($kimaiId);
        if ($member['system'] || !$member['enabled']) {
            throw new \DomainException('A criação de contatos está disponível somente para cooperados ativos.');
        }
        $linked = $this->profileFields->findByKimaiId($kimaiId);
        if ($linked === null || (string) ($linked['values']['kimai_user_id'] ?? '') !== (string) $kimaiId) {
            throw new \DomainException('Confirme primeiro o vínculo deste cooperado com o Nextcloud.');
        }
        if (!$this->profileFields->userExists($linked['uid'])) {
            throw new \DomainException('A conta Nextcloud vinculada não existe mais. Corrija o vínculo antes de criar contato.');
        }
        $existingId = (int) ($linked['values']['akaunting_contact_id'] ?? 0);
        if ($existingId > 0) {
            return ['status' => 'linked', 'uid' => $linked['uid'],
                'name' => $member['alias'], 'tax_hint' => '', 'contacts' => []];
        }
        $taxKey = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
        $tax = (string) preg_replace('/\D/', '', (string) ($linked['values'][$taxKey] ?? ''));
        if (!in_array(strlen($tax), [11, 14], true)) {
            throw new \DomainException('CPF/CNPJ não cadastrado ou inválido no Nextcloud. Corrija o perfil antes de criar o contato.');
        }
        $contacts = $this->akauntingContacts->findByTaxNumber($tax);
        return [
            'status' => $contacts !== [] ? 'existing' : 'available',
            'uid' => $linked['uid'],
            'name' => $member['alias'],
            'tax_hint' => (strlen($tax) === 11 ? 'CPF' : 'CNPJ') . ' final ' . substr($tax, -3),
            'contacts' => $contacts,
        ];
    }

    /**
     * @return array{id:int,name:string}
     */
    public function createContactForMember(int $kimaiId, string $expectedUid, string $name): array
    {
        $proposal = $this->contactProvisioning($kimaiId);
        if ($proposal['status'] === 'linked') {
            throw new \DomainException('Este cooperado já tem um contato financeiro vinculado.');
        }
        if ($proposal['status'] === 'existing') {
            throw new \DomainException('Contato existente localizado. Selecione-o no cadastro em vez de criar outro.');
        }
        if ($expectedUid === '' || !hash_equals($proposal['uid'], $expectedUid)) {
            throw new \DomainException('A conta Nextcloud foi alterada. Reabra o cadastro e confirme a identidade.');
        }
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120
            || preg_match("/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/uD", $name) !== 1) {
            throw new \InvalidArgumentException('Informe o nome completo válido para o contato financeiro.');
        }
        // Profile Fields is the sole fiscal source. Do not accept a posted
        // CPF/CNPJ or auto-link the new ID to Nextcloud in this operation.
        $linked = $this->profileFields->findByKimaiId($kimaiId);
        $taxKey = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
        if ($linked === null || $linked['uid'] !== $expectedUid
            || (string) ($linked['values']['kimai_user_id'] ?? '') !== (string) $kimaiId) {
            throw new \DomainException('O vínculo Nextcloud foi alterado. Reabra o cadastro.');
        }
        if ((int) ($linked['values']['akaunting_contact_id'] ?? 0) > 0) {
            throw new \DomainException('Contato já vinculado no Nextcloud. Reabra o cadastro.');
        }
        $tax = (string) preg_replace('/\D/', '', (string) ($linked['values'][$taxKey] ?? ''));
        $id = $this->akauntingContacts->createMissingContact($name, $tax);
        return ['id' => $id, 'name' => $name];
    }

    public function prepareLinkFields(): void
    {
        $this->profileFields->ensureLinkDefinitions();
    }

    /** Validates both identities and uniqueness before any remote modification. */
    public function linkMember(int $kimaiId, string $uid, int $contactId, ?int $targetKimaiId = null): void
    {
        $member = $this->user($kimaiId);
        $targetKimaiId ??= $kimaiId;
        $targetMember = $targetKimaiId === $kimaiId ? $member : $this->user($targetKimaiId);
        if ($member['system'] || $targetMember['system'] || $uid === '' || $contactId <= 0
            || !$this->profileFields->userExists($uid)) {
            throw new \DomainException('Selecione uma conta Nextcloud e um contato financeiro válidos.');
        }
        $definitions = $this->profileFields->activeDefinitions();
        foreach (['kimai_user_id', 'akaunting_contact_id'] as $key) {
            if (($definitions[$key]['type'] ?? null) !== 'text') {
                throw new \DomainException('Configure os dois campos de vínculo no Profile Fields antes de salvar.');
            }
        }
        $existing = $this->profileFields->getFieldsForUser($uid);
        $currentKimai = trim((string) ($existing['kimai_user_id'] ?? ''));
        if ($currentKimai !== '' && $currentKimai !== (string) $kimaiId && $currentKimai !== (string) $targetKimaiId) {
            throw new \DomainException('Conta Nextcloud já vinculada a outro usuário Kimai.');
        }
        $currentFinancial = trim((string) ($existing['akaunting_contact_id'] ?? ''));
        $financial = $this->akauntingContacts->overview($contactId);
        // A financial link identifies an existing contact; it does not edit
        // that contact. Missing fields required by Akaunting's PUT API must
        // not prevent associating an otherwise valid record.
        if ($financial['id'] !== $contactId) {
            throw new \DomainException('Contato financeiro inválido ou de outra empresa no Akaunting.');
        }
        $taxFieldKey = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
        $profileTax = (string) preg_replace('/\\D/', '', (string) ($existing[$taxFieldKey] ?? ''));
        $contactTax = (string) preg_replace('/\\D/', '', (string) $financial['tax_number']);
        if (!in_array(strlen($profileTax), [11, 14], true) || $profileTax !== $contactTax) {
            throw new \DomainException('CPF/CNPJ divergente entre o Profile Fields e o contato do Akaunting.');
        }
        $otherKimai = $this->profileFields->findUserUidByLink('kimai_user_id', $targetKimaiId);
        if ($otherKimai !== null && $otherKimai !== $uid) {
            throw new \DomainException('Usuário Kimai já vinculado a outra conta Nextcloud.');
        }
        $other = $this->profileFields->findUserUidByLink('akaunting_contact_id', $contactId);
        if ($other !== null && $other !== $uid) {
            throw new \DomainException('Contato Akaunting já vinculado a outra conta Nextcloud.');
        }

        if ($currentFinancial !== (string) $contactId) {
            $this->updateNextcloud($uid, 'akaunting_contact_id', (string) $contactId);
        }
        if ($currentKimai !== (string) $targetKimaiId) {
            $this->updateNextcloud($uid, 'kimai_user_id', (string) $targetKimaiId);
        }
    }

    public function updateNextcloud(string $uid, string $field, string|float|int $value): void
    {
        $this->profileFields->updateUserField($uid, $field, $value);
        $fresh = $this->profileFields->getFieldsForUser($uid);
        if ((string) ($fresh[$field] ?? '') !== (string) $value) {
            throw new RuntimeException('Nextcloud não confirmou o novo valor. Confira a origem antes de repetir.');
        }
    }

}
