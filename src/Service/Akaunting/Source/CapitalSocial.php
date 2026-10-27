<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Akaunting\Source;

use App\Service\Akaunting\CapitalSocialSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;

/** Read-only Akaunting source adapter. Capital contributions are not sales. */
final class CapitalSocial
{
    private CapitalSocialSettings $settings;

    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony */
    public function __construct(
        private Connection $accounting,
        ?CapitalSocialSettings $settings = null,
    ) {
        $this->settings = $settings ?? CapitalSocialSettings::fromEnvironment();
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $sql = <<<SQL
            SELECT * FROM (
                SELECT t.contact_id, c.name, t.amount,
                    t.paid_at AS due_at, t.id, 'transaction' AS `table`
                FROM transactions t
                JOIN contacts c ON c.id = t.contact_id
                WHERE t.category_id = :category_id
                    AND t.document_id IS NULL
                    AND t.deleted_at IS NULL
                UNION
                SELECT d.contact_id, d.contact_name AS name, d.amount,
                    d.due_at, d.id, 'documents' AS `table`
                FROM documents d
                WHERE d.category_id = :category_id
                    AND d.deleted_at IS NULL
                UNION
                SELECT d.contact_id, d.contact_name AS name, di.price AS amount,
                    d.due_at, d.id, 'documents_item' AS `table`
                FROM document_items di
                JOIN documents d ON d.id = di.document_id
                WHERE di.item_id = :item_id
                    AND d.deleted_at IS NULL
                    AND di.deleted_at IS NULL
            ) x
            ORDER BY x.name, x.due_at
            SQL;
        $rows = $this->accounting->executeQuery(
            $sql,
            [
                'category_id' => $this->settings->categoryId,
                'item_id' => $this->settings->itemId,
            ],
            [
                'category_id' => ParameterType::INTEGER,
                'item_id' => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        if ($rows === []) {
            throw new Exception('Sem capital social');
        }

        return $rows;
    }
}
