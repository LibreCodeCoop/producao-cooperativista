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
        // A matching login in Kimai is not proof of identity in Nextcloud.
        // Never preselect an unconfirmed account: the administrator must search
        // and explicitly confirm the correct Nextcloud UID before any write.
        return ['exists' => false, 'uid' => '', 'values' => []];
    }

    /**
     * Identity summaries support administrative visual inspection only.
     * Reading a record does not establish that the profiles belong to the
     * same person, nor does it change the stored links.
     *
     * @return array{source:string,id:string,name:string,username:string,email:string,enabled:bool|null,kind:string,tax_hint:string,tax_match:string,linked_account:string}
     */
    public function inspectIdentity(string $source, string $identifier, string $nextcloudUid = ''): array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 128) {
            throw new \InvalidArgumentException('Selecione um identificador válido para conferir.');
        }
        $identity = [
            'source' => $source, 'id' => $identifier, 'name' => '', 'username' => '',
            'email' => '', 'enabled' => null, 'kind' => '', 'tax_hint' => '',
            'tax_match' => '', 'linked_account' => '',
        ];
        if ($source === 'nextcloud') {
            $record = $this->profileFields->userSummary($identifier);
            $fields = $this->profileFields->getFieldsForUser($identifier);
            $identity['name'] = $record['display_name'];
            $identity['username'] = $record['uid'];
            $identity['email'] = $record['email'];
            $identity['enabled'] = $record['enabled'];
            $kimai = trim((string) ($fields['kimai_user_id'] ?? ''));
            $identity['linked_account'] = $kimai !== '' ? 'Kimai #' . $kimai : '';
            return $identity;
        }
        if (!preg_match('/^[1-9][0-9]*$/D', $identifier)
            || filter_var($identifier, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Identificador numérico inválido para a origem.');
        }
        $id = (int) $identifier;
        if ($source === 'kimai') {
            $raw = $this->doRequestKimai('/users/' . $id);
            if ((int) ($raw['id'] ?? 0) !== $id || trim((string) ($raw['username'] ?? '')) === '') {
                throw new \DomainException('Usuário não encontrado no Kimai.');
            }
            $identity['name'] = trim((string) (($raw['alias'] ?? '') ?: $raw['username']));
            $identity['username'] = (string) $raw['username'];
            $identity['email'] = trim((string) ($raw['email'] ?? ''));
            $identity['enabled'] = (bool) ($raw['enabled'] ?? true);
            $identity['kind'] = !empty($raw['systemAccount']) ? 'Conta de serviço' : 'Usuário';
            $identity['linked_account'] = $this->profileFields->findUserUidByLink('kimai_user_id', $id) ?? '';
            return $identity;
        }
        if ($source === 'akaunting') {
            $record = $this->akauntingContacts->overview($id);
            $identity['name'] = $record['name'];
            $identity['kind'] = $record['type'] === 'employee' ? 'Funcionário' : 'Fornecedor';
            $tax = (string) preg_replace('/\D/', '', $record['tax_number']);
            if (in_array(strlen($tax), [11, 14], true)) {
                $identity['tax_hint'] = (strlen($tax) === 11 ? 'CPF' : 'CNPJ') . ' final ' . substr($tax, -3);
            }
            $identity['linked_account'] = $this->profileFields->findUserUidByLink('akaunting_contact_id', $id) ?? '';
            $nextcloudUid = trim($nextcloudUid);
            if ($nextcloudUid !== '' && mb_strlen($nextcloudUid) <= 128
                && $this->profileFields->userExists($nextcloudUid)) {
                $fields = $this->profileFields->getFieldsForUser($nextcloudUid);
                $key = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
                $profileTax = (string) preg_replace('/\D/', '', (string) ($fields[$key] ?? ''));
                $identity['tax_match'] = !in_array(strlen($tax), [11, 14], true)
                    || !in_array(strlen($profileTax), [11, 14], true)
                    ? 'missing' : ($tax === $profileTax ? 'match' : 'different');
            }
            return $identity;
        }
        throw new \InvalidArgumentException('Origem de identidade não permitida.');
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
    public function contactProvisioning(int $kimaiId, string $selectedUid): array
    {
        $member = $this->user($kimaiId);
        if ($member['system'] || !$member['enabled']) {
            throw new \DomainException('A criação de contatos está disponível somente para cooperados ativos.');
        }
        $selectedUid = trim($selectedUid);
        if ($selectedUid === '' || mb_strlen($selectedUid) > 128
            || !$this->profileFields->userExists($selectedUid)) {
            throw new \DomainException('Selecione uma conta Nextcloud existente antes de consultar o contato.');
        }
        $linked = $this->profileFields->findByKimaiId($kimaiId);
        if ($linked !== null && $linked['uid'] !== $selectedUid) {
            throw new \DomainException('Este ID Kimai já pertence a outra conta Nextcloud. Confira os vínculos.');
        }
        // The first-time flow has no Kimai link yet. A selected administrator-
        // confirmed UID can be inspected without writing the technical link.
        // Contact creation and saving both links remain separate actions.
        $values = $linked !== null
            ? $linked['values'] : $this->profileFields->getFieldsForUser($selectedUid);
        $currentKimaiId = trim((string) ($values['kimai_user_id'] ?? ''));
        if ($currentKimaiId !== '' && $currentKimaiId !== (string) $kimaiId) {
            throw new \DomainException('Esta conta Nextcloud já pertence a outro usuário Kimai.');
        }
        $existingId = (int) ($values['akaunting_contact_id'] ?? 0);
        if ($existingId > 0) {
            return ['status' => 'linked', 'uid' => $selectedUid,
                'name' => $member['alias'], 'tax_hint' => '', 'contacts' => []];
        }
        $taxKey = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
        $tax = (string) preg_replace('/\D/', '', (string) ($values[$taxKey] ?? ''));
        if (!in_array(strlen($tax), [11, 14], true)) {
            $instructions = $currentKimaiId === ''
                ? 'Confirme o vínculo Kimai-Nextcloud acima, depois preencha CPF/CNPJ em Dados do cooperado.'
                : 'Preencha CPF/CNPJ em Dados do cooperado antes de criar o contato.';
            throw new \DomainException('CPF/CNPJ não cadastrado ou inválido no Nextcloud. ' . $instructions);
        }
        $contacts = $this->akauntingContacts->findByTaxNumber($tax);
        return [
            'status' => $contacts !== [] ? 'existing' : 'available',
            'uid' => $selectedUid,
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
        $proposal = $this->contactProvisioning($kimaiId, $expectedUid);
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
        // Read the selected account again; a first-time cooperado is allowed
        // to have no Kimai link, but cannot already belong to another one.
        $values = $this->profileFields->getFieldsForUser($expectedUid);
        $currentKimaiId = trim((string) ($values['kimai_user_id'] ?? ''));
        if ($currentKimaiId !== '' && $currentKimaiId !== (string) $kimaiId) {
            throw new \DomainException('O vínculo Nextcloud foi alterado. Reabra o cadastro.');
        }
        if ((int) ($values['akaunting_contact_id'] ?? 0) > 0) {
            throw new \DomainException('Contato já vinculado no Nextcloud. Reabra o cadastro.');
        }
        $taxKey = trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
        $tax = (string) preg_replace('/\D/', '', (string) ($values[$taxKey] ?? ''));
        $id = $this->akauntingContacts->createMissingContact($name, $tax);
        return ['id' => $id, 'name' => $name];
    }

    /** Whether both administrator-only technical references already exist. */
    public function linkFieldsReady(): bool
    {
        $definitions = $this->profileFields->activeDefinitions();
        foreach (['kimai_user_id', 'akaunting_contact_id'] as $key) {
            $field = $definitions[$key] ?? null;
            if ($field === null || $field['type'] !== 'text'
                || ($field['edit_policy'] ?? null) !== 'admins'
                || ($field['exposure_policy'] ?? null) !== 'hidden') {
                return false;
            }
        }
        return true;
    }

    /**
     * Explicitly confirm the Kimai-to-Nextcloud identity independently of
     * fiscal data and financial contacts. This never creates or links a contact.
     */
    public function confirmKimaiLink(int $kimaiId, string $uid): void
    {
        $member = $this->user($kimaiId);
        if ($member['system'] || !$member['enabled'] || trim($uid) === ''
            || !$this->profileFields->userExists($uid)) {
            throw new \DomainException('Selecione uma conta Nextcloud existente para um cooperado ativo.');
        }
        $definition = $this->profileFields->activeDefinitions()['kimai_user_id'] ?? null;
        if ($definition === null || $definition['type'] !== 'text'
            || ($definition['edit_policy'] ?? null) !== 'admins'
            || ($definition['exposure_policy'] ?? null) !== 'hidden') {
            throw new \DomainException('Campo de vínculo Kimai indisponível ou sem proteção administrativa. Prepare os campos antes de confirmar.');
        }
        $values = $this->profileFields->getFieldsForUser($uid);
        $current = trim((string) ($values['kimai_user_id'] ?? ''));
        if ($current !== '' && $current !== (string) $kimaiId) {
            throw new \DomainException('Esta conta Nextcloud já pertence a outro usuário Kimai.');
        }
        $otherUid = $this->profileFields->findUserUidByLink('kimai_user_id', $kimaiId);
        if ($otherUid !== null && $otherUid !== $uid) {
            throw new \DomainException('O usuário Kimai já está vinculado a outra conta Nextcloud.');
        }
        if ($current !== (string) $kimaiId) {
            $this->updateNextcloud($uid, 'kimai_user_id', (string) $kimaiId);
        }
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
