<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cross-competence appropriation journal, distinct from the financial ledger.
 *
 * Only a successful publication confirms allocation. An uncertain or partial
 * Akaunting write leaves a reservation for explicit reconciliation, never an
 * automatic retry. Staging reset does not remove this journal.
 */
final class IncomeAppropriationLedger
{
    /** @var array<string, array{confirmed:int, reserved:bool}>|null */
    private ?array $previous = null;

    /** @psalm-suppress PossiblyUnusedMethod Injected by Symfony DI. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private readonly Connection $db,
    ) {
    }

    /** @return array{type:string,id:int}|null */
    public static function identity(array $row): ?array
    {
        $isInvoice = in_array($row['table'] ?? '', ['invoice', 'invoice_transaction', 'invoice_forecast'], true);
        if (($row['category_type'] ?? null) !== 'income'
            || (!$isInvoice && ($row['type'] ?? null) !== 'income')) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new DomainException('Receita sem ID positivo: não é possível controlar apropriação.');
        }
        if (in_array($row['table'] ?? '', ['invoice', 'invoice_transaction', 'invoice_forecast'], true)) {
            return ['type' => 'invoice', 'id' => $id];
        }
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        $document = is_array($metadata) ? (int) ($metadata['document_id'] ?? 0) : 0;
        return $document > 0
            ? ['type' => 'invoice', 'id' => $document]
            : ['type' => 'transaction', 'id' => $id];
    }

    /** @return array{confirmed:int,reserved:bool} */
    private function previousFor(array $identity): array
    {
        if ($this->previous === null) {
            $this->previous = [];
            $rows = $this->db->fetchAllAssociative(
                'SELECT source_type, source_id, state, amount_cents '
                . 'FROM production_income_appropriations WHERE company_id = :company',
                ['company' => $this->company()]
            );
            foreach ($rows as $item) {
                $key = $item['source_type'] . ':' . $item['source_id'];
                $this->previous[$key] ??= ['confirmed' => 0, 'reserved' => false];
                if ($item['state'] === 'reserved') {
                    $this->previous[$key]['reserved'] = true;
                } elseif ($item['state'] === 'confirmed') {
                    $this->previous[$key]['confirmed'] += (int) $item['amount_cents'];
                } else {
                    throw new DomainException('Estado desconhecido no histórico de apropriação.');
                }
            }
        }
        return $this->previous[$identity['type'] . ':' . $identity['id']]
            ?? ['confirmed' => 0, 'reserved' => false];
    }

    private function company(): int
    {
        // Symfony's dotenv snapshot is authoritative if another test or job
        // changes the process environment after the container was booted.
        $configured = getenv('AKAUNTING_COMPANY_ID');
        if ($configured === false || $configured === '') {
            $configured = $_ENV['AKAUNTING_COMPANY_ID'] ?? $_SERVER['AKAUNTING_COMPANY_ID'] ?? null;
        }
        $company = (int) $configured;
        if ($company < 1) {
            throw new DomainException('ID da empresa inválido para o controle de apropriação.');
        }
        return $company;
    }

    private function totalCents(array $row, array $identity): int
    {
        if ($identity['type'] === 'transaction') {
            return DecimalAllocation::cents((float) $row['amount']);
        }
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        $face = (float) ($metadata['amount'] ?? 0);
        if ($face <= 0) {
            // Legacy imported snapshots and linked bank receipts do not always
            // contain the original document total in embedded JSON.
            $face = (float) ($this->db->fetchOne(
                'SELECT amount FROM invoices WHERE id = :id AND type = :type',
                ['id' => $identity['id'], 'type' => 'invoice']
            ) ?: 0);
        }
        if ($face <= 0) {
            throw new DomainException('Fatura vinculada sem valor original: reimporte o documento antes da produção.');
        }
        return DecimalAllocation::cents($face);
    }

    /**
     * Reduce a source by the amount already allocated in earlier publications.
     * Do not suppress an ambiguous reservation or treat a paid invoice as new
     * merely because its bank receipt is dated in a later month.
     *
     * @return array<string,mixed>|null
     */
    public function remaining(array $row, int $allocatedInThisRunCents = 0): ?array
    {
        $identity = self::identity($row);
        if ($identity === null) {
            return $row;
        }
        $prior = $this->previousFor($identity);
        if ($prior['reserved']) {
            throw new DomainException(sprintf(
                'Existe publicação sem resultado financeiro confirmado para %s #%d. '
                . 'Concilie os documentos no Akaunting antes de reprocessar.',
                $identity['type'],
                $identity['id']
            ));
        }
        $face = $this->totalCents($row, $identity);
        $remaining = $face - $prior['confirmed'] - $allocatedInThisRunCents;
        if ($remaining < 0) {
            throw new DomainException('Apropriação prévia superior ao total da receita: revisão contábil necessária.');
        }
        if ($remaining === 0) {
            return null;
        }
        $current = DecimalAllocation::cents((float) $row['amount']);
        if ($current <= 0) {
            return null;
        }
        if ($current > $remaining) {
            $ratio = $remaining / $current;
            $row['bruto'] = (float) ($row['bruto'] ?? $row['amount']) * $ratio;
            $row['amount'] = DecimalAllocation::amount($remaining);
        }
        return $row;
    }

    /**
     * @param array<int|string,array<string,mixed>> $incomeRows Already computed eligible sources
     */
    public function reserve(array $incomeRows, string $competence, string $revision): void
    {
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $competence)
            || !preg_match('/^[a-f0-9]{64}$/D', $revision)) {
            throw new DomainException('Revisão e competência obrigatórias para publicar a receita.');
        }

        /** @var array<string, array{type:string,id:int,cents:int,face:int}> $selected */
        $selected = [];
        foreach ($incomeRows as $row) {
            $id = self::identity($row);
            if ($id === null) {
                continue;
            }
            $key = $id['type'] . ':' . $id['id'];
            $face = $this->totalCents($row, $id);
            $selected[$key] ??= ['type' => $id['type'], 'id' => $id['id'], 'cents' => 0, 'face' => $face];
            if ($selected[$key]['face'] !== $face) {
                throw new DomainException('Valor original divergente para a mesma receita.');
            }
            $selected[$key]['cents'] += DecimalAllocation::cents((float) $row['amount']);
        }
        if ($selected === []) {
            throw new DomainException('Não há receita distribuível para registrar na publicação.');
        }

        $this->db->beginTransaction();
        try {
            foreach ($selected as $item) {
                $prior = $this->previousFor(['type' => $item['type'], 'id' => $item['id']]);
                if ($prior['reserved'] || $prior['confirmed'] + $item['cents'] > $item['face']) {
                    throw new DomainException(sprintf(
                        'Receita %s #%d já apropriada ou acima do valor original.',
                        $item['type'],
                        $item['id']
                    ));
                }
                $this->db->insert('production_income_appropriations', [
                    'company_id' => $this->company(),
                    'source_type' => $item['type'],
                    'source_id' => $item['id'],
                    'competence' => $competence,
                    'amount_cents' => $item['cents'],
                    'source_total_cents' => $item['face'],
                    'revision' => $revision,
                    'state' => 'reserved',
                    'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
            }
            $this->db->commit();
            $this->previous = null;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function confirm(string $competence, string $revision): void
    {
        $changed = $this->db->executeStatement(
            'UPDATE production_income_appropriations SET state = :state, confirmed_at = :at '
            . 'WHERE company_id = :company AND competence = :competence AND revision = :revision AND state = :reserved',
            [
                'state' => 'confirmed',
                'at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'company' => $this->company(),
                'competence' => $competence,
                'revision' => $revision,
                'reserved' => 'reserved',
            ]
        );
        if ($changed < 1) {
            throw new DomainException('Nenhuma apropriação pendente encontrada após publicar; confira o histórico.');
        }
        $this->previous = null;
    }
}
