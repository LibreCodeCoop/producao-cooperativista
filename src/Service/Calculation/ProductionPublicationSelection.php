<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use App\Service\Cooperado;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps a payment/publication decision separate from the economic apportionment.
 * The fingerprint ties a review to a specific calculation; it is not an auth token.
 */
final readonly class ProductionPublicationSelection
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony dependency injection entry point. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $staging,
    ) {
    }

    /**
     * @param array<string, Cooperado> $members
     * @return array{revision: string, ids: array<string, int>}
     */
    public function snapshot(array $members, string $competence): array
    {
        $taxToId = [];
        $seenIds = [];
        foreach ($this->staging->fetchAllAssociative(
            'SELECT id, tax_number FROM users WHERE tax_number IS NOT NULL AND tax_number <> \'\''
        ) as $row) {
            $tax = (string) $row['tax_number'];
            if (!isset($members[$tax])) {
                continue; // Unrelated Kimai accounts must not block this competence.
            }
            $id = (int) $row['id'];
            if ($id <= 0 || isset($taxToId[$tax])) {
                throw new DomainException('Cadastros Kimai duplicados ou inválidos na base importada. Confira a importação.');
            }
            $taxToId[$tax] = $id;
        }

        $fingerprint = [];
        $ids = [];
        foreach ($members as $tax => $member) {
            $tax = (string) $tax;
            $id = $taxToId[$tax] ?? 0;
            if ($id <= 0 || isset($seenIds[$id])) {
                throw new DomainException('Não foi possível identificar exclusivamente o cooperado no Kimai. Reimporte e confira o cadastro.');
            }
            $seenIds[$id] = true;
            $ids[$tax] = $id;
            $values = $member->getProducaoCooperativista()->getValues()->toArray();
            // Values are serialized to stable decimal representations.
            $fingerprint[$id] = [
                'tax_number' => $tax,
                'contact' => $member->getAkauntingContactId(),
                'base' => DecimalAllocation::decimal($values['base_producao']),
                'gross' => DecimalAllocation::decimal($values['bruto']),
                'net' => DecimalAllocation::decimal($values['liquido']),
                'discount' => DecimalAllocation::decimal($values['liquid_discount']),
                'weight' => DecimalAllocation::decimal($member->getWeight()),
                'dependents' => (int) $member->getDependentes(),
                'document' => (string) $values['document_number'],
            ];
        }
        ksort($fingerprint, SORT_NUMERIC);
        return [
            'revision' => hash('sha256', json_encode([$competence, $fingerprint], JSON_THROW_ON_ERROR)),
            'ids' => $ids,
        ];
    }

    /**
     * @param array<string, Cooperado> $members
     * @param list<int> $excludedIds
     * @return array<string, Cooperado>
     */
    public function included(array $members, string $competence, string $expectedRevision, array $excludedIds): array
    {
        if ($members === []) {
            throw new DomainException('Não há cooperados com produção para publicar nesta competência.');
        }
        $snapshot = $this->snapshot($members, $competence);
        if ($expectedRevision === '' || !hash_equals($snapshot['revision'], $expectedRevision)) {
            throw new DomainException(
                'A apuração mudou desde a conferência. Atualize a prévia e revise novamente antes de publicar.'
            );
        }
        $byId = array_flip($snapshot['ids']);
        $exclude = [];
        foreach ($excludedIds as $id) {
            if ($id <= 0 || !isset($byId[$id]) || isset($exclude[$id])) {
                throw new DomainException('Seleção de cooperados inválida ou duplicada. Atualize a prévia.');
            }
            $exclude[$id] = true;
        }
        if (count($members) > 0 && count($exclude) === count($members)) {
            throw new DomainException('Todos os cooperados foram desmarcados. Não há produção individual a publicar.');
        }
        return array_filter(
            $members,
            static fn (Cooperado $member): bool => !isset($exclude[$snapshot['ids'][$member->getTaxNumber()]])
        );
    }
}
