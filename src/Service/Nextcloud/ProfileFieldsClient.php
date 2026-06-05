<?php

declare(strict_types=1);

namespace App\Service\Nextcloud;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProfileFieldsClient
{
    /** @var array<int, string>|null */
    private ?array $definitionFieldKeysById = null;

    /** @var array<string, array<string, mixed>> */
    private array $fieldsByUserUid = [];

    /** @var array<string, array<string, mixed>> */
    private array $fieldsByTaxNumber = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getBaseUrl() !== ''
            && $this->getAuthUser() !== ''
            && $this->getAuthToken() !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldsForUser(string $userUid): array
    {
        if (!$this->isConfigured() || $userUid === '') {
            return [];
        }

        if (isset($this->fieldsByUserUid[$userUid])) {
            return $this->fieldsByUserUid[$userUid];
        }

        $fields = [];
        foreach ($this->requestOcs(
            '/ocs/v2.php/apps/profile_fields/api/v1/users/' . rawurlencode($userUid) . '/values',
            allowNotFound: true,
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $definitionId = (int) ($row['field_definition_id'] ?? 0);
            if ($definitionId <= 0) {
                continue;
            }

            $fieldKey = $this->getDefinitionFieldKey($definitionId);
            if ($fieldKey === null) {
                continue;
            }

            $fields[$fieldKey] = $this->normalizeValue($row['value'] ?? null);
        }

        $this->fieldsByUserUid[$userUid] = $fields;

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldsForTaxNumber(string $taxNumber): array
    {
        $taxNumber = trim($taxNumber);
        if (!$this->isConfigured() || $taxNumber === '') {
            return [];
        }

        if (isset($this->fieldsByTaxNumber[$taxNumber])) {
            return $this->fieldsByTaxNumber[$taxNumber];
        }

        $lookup = $this->requestOcs(
            '/ocs/v2.php/apps/profile_fields/api/v1/users/lookup',
            method: 'POST',
            options: [
                'json' => [
                    'fieldKey' => $this->getTaxNumberFieldKey(),
                    'fieldValue' => $taxNumber,
                ],
            ],
            allowNotFound: true,
        );

        $fields = [];
        $lookupFields = $lookup['fields'] ?? null;
        if (is_array($lookupFields)) {
            foreach ($lookupFields as $fieldKey => $field) {
                if (!is_string($fieldKey) || !is_array($field)) {
                    continue;
                }

                $fields[$fieldKey] = $this->normalizeValue($field['value'] ?? null);
            }
        }

        $this->fieldsByTaxNumber[$taxNumber] = $fields;

        return $fields;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function enrichUser(array $user): array
    {
        $taxNumber = trim((string) ($user['tax_number'] ?? ''));
        if ($taxNumber === '') {
            return $user;
        }

        $fields = $this->resolveFieldsForUser($user, $taxNumber);
        if ($fields === []) {
            return $user;
        }

        return $this->applyFieldsToUser($user, $fields);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function resolveFieldsForUser(array $user, string $taxNumber): array
    {
        $fields = $this->getFieldsForTaxNumber($taxNumber);
        if ($fields !== []) {
            return $fields;
        }

        $userUid = $this->getUserUid($user);
        if ($userUid !== '') {
            $this->logger->debug('Profile Fields lookup by tax number returned no result; falling back to user UID lookup.', [
                'tax_number' => $taxNumber,
                'user_uid' => $userUid,
            ]);
        }

        return $this->getFieldsForUser($userUid);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function applyFieldsToUser(array $user, array $fields): array
    {
        $this->applyTrimmedScalarField($user, $fields, $this->getTaxNumberFieldKey(), 'tax_number');
        $this->applyNumericField($user, $fields, $this->getWeightFieldKey(), 'peso', static fn ($value): float => (float) $value);
        $this->applyNumericField($user, $fields, $this->getDependentsFieldKey(), 'dependents', static fn ($value): int => (int) $value);

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function applyTrimmedScalarField(array &$user, array $fields, string $fieldKey, string $userKey): void
    {
        $fieldValue = $fields[$fieldKey] ?? null;
        if (!is_scalar($fieldValue)) {
            return;
        }

        $normalizedValue = trim((string) $fieldValue);
        if ($normalizedValue !== '') {
            $user[$userKey] = $normalizedValue;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param callable(scalar): int|float $caster
     */
    private function applyNumericField(array &$user, array $fields, string $fieldKey, string $userKey, callable $caster): void
    {
        $fieldValue = $fields[$fieldKey] ?? null;
        if (is_scalar($fieldValue) && is_numeric((string) $fieldValue)) {
            $user[$userKey] = $caster($fieldValue);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function getUserUid(array $user): string
    {
        return trim((string) ($user['kimai_username'] ?? $user['username'] ?? ''));
    }

    private function getDefinitionFieldKey(int $definitionId): ?string
    {
        if ($this->definitionFieldKeysById === null) {
            $this->definitionFieldKeysById = [];
            foreach ($this->requestOcs('/ocs/v2.php/apps/profile_fields/api/v1/definitions') as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $id = (int) ($definition['id'] ?? 0);
                $fieldKey = $definition['field_key'] ?? $definition['fieldKey'] ?? null;
                if ($id <= 0 || !is_string($fieldKey) || $fieldKey === '') {
                    continue;
                }

                $this->definitionFieldKeysById[$id] = $fieldKey;
            }
        }

        return $this->definitionFieldKeysById[$definitionId] ?? null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function requestOcs(
        string $path,
        string $method = 'GET',
        array $options = [],
        bool $allowNotFound = false,
    ): array {
        if (!$this->isConfigured()) {
            return [];
        }

        $url = rtrim($this->getBaseUrl(), '/') . $path;
        $options = array_replace_recursive([
            'auth_basic' => [$this->getAuthUser(), $this->getAuthToken()],
            'headers' => [
                'Accept' => 'application/json',
                'OCS-APIRequest' => 'true',
            ],
        ], $options);

        $response = $this->httpClient->request($method, $url, $options);

        $statusCode = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($statusCode === 404 && $allowNotFound) {
            return [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'Falha ao consultar a API Profile Fields (%d): %s',
                $statusCode,
                $this->getErrorMessage($payload),
            ));
        }

        $metaStatusCode = (int) ($payload['ocs']['meta']['statuscode'] ?? 100);
        if ($metaStatusCode >= 400) {
            throw new RuntimeException(sprintf(
                'Falha ao consultar a API Profile Fields (%d): %s',
                $metaStatusCode,
                $this->getErrorMessage($payload),
            ));
        }

        $data = $payload['ocs']['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $this->normalizeValue($value['value']);
        }

        return $value;
    }

    private function getErrorMessage(array $payload): string
    {
        $message = $payload['ocs']['data']['message']
            ?? $payload['ocs']['meta']['message']
            ?? $payload['message']
            ?? 'Resposta inesperada';

        return is_string($message) && $message !== '' ? $message : 'Resposta inesperada';
    }

    private function getBaseUrl(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_API_BASE_URL'));
    }

    private function getAuthUser(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_AUTH_USER'));
    }

    private function getAuthToken(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_AUTH_TOKEN'));
    }

    private function getTaxNumberFieldKey(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number';
    }

    private function getWeightFieldKey(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_WEIGHT_FIELD_KEY')) ?: 'weight';
    }

    private function getDependentsFieldKey(): string
    {
        return trim((string) getenv('PROFILE_FIELDS_DEPENDENTS_FIELD_KEY')) ?: 'dependents';
    }
}
