<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use DomainException;

/**
 * Resolve source documents as a bounded API batch, regardless of which
 * competencies were previously loaded into the ETL database.
 *
 * Notes on a document may contain an approved "Transação do mês" field;
 * using only staged documents silently changes the financial period when
 * the same competence is imported in a different order.
 */
final readonly class AssociatedDocumentResolver
{
    private const int BATCH_SIZE = 50;

    /** @psalm-suppress PossiblyUnusedMethod Symfony DI constructor. */
    public function __construct(
        private Dataset $dataset,
        private ParseText $parser,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $transactions Akaunting API rows
     * @return array<int, array{id:int, customer_reference:string, metadata:string}>
     */
    public function resolve(array $transactions, int $companyId): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), array_column($transactions, 'document_id')),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $resolved = [];
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $remaining = array_fill_keys($chunk, true);
            foreach (['invoice', 'bill'] as $type) {
                if ($remaining === []) {
                    break;
                }
                $search = 'type:' . $type . ' id:' . implode(',', array_keys($remaining));
                $documents = $this->dataset->list('/api/documents', [
                    'company_id' => $companyId,
                    'search' => $search,
                ]);
                foreach ($documents as $document) {
                    $id = (int) ($document['id'] ?? 0);
                    if (!isset($remaining[$id])
                        || ($document['type'] ?? null) !== $type
                        || (isset($document['company_id']) && (int) $document['company_id'] !== $companyId)
                    ) {
                        throw new DomainException(
                            'A API do Akaunting retornou um documento fora do conjunto solicitado. '
                            . 'Confira a empresa, o tipo e o filtro de identificadores.'
                        );
                    }
                    $resolved[$id] = [
                        'id' => $id,
                        'customer_reference' => $this->customerReference($document),
                        'metadata' => json_encode($document, JSON_THROW_ON_ERROR),
                    ];
                    unset($remaining[$id]);
                }
            }
            if ($remaining !== []) {
                throw new DomainException(sprintf(
                    'Documentos associados a transações indisponíveis no Akaunting (IDs: %s). '
                    . 'Confira se existem, pertencem à empresa %d e se a API permite consultá-los. '
                    . 'A competência não será inferida apenas pela data de pagamento.',
                    implode(', ', array_keys($remaining)),
                    $companyId
                ));
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $document */
    private function customerReference(array $document): string
    {
        $reference = trim((string) ($document['customer_reference'] ?? ''));
        if ($reference !== '') {
            return $reference;
        }

        $fields = $this->parser->do((string) ($document['notes'] ?? ''));
        if (!empty($fields['customer'])) {
            $reference = (string) $fields['customer'];
            if (!empty($fields['sector'])) {
                $reference .= '|' . strtolower((string) $fields['sector']);
            }
            return $reference;
        }
        if (!empty($document['contact']['reference'])) {
            return (string) $document['contact']['reference'];
        }
        if (!empty($document['contact']['tax_number'])) {
            return (string) $document['contact']['tax_number'];
        }
        // Never substitute the cooperative's CNPJ for a missing client.
        // Movimentacao validates mandatory customer references by category.
        return '';
    }
}
