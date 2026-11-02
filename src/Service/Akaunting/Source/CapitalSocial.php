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
use InvalidArgumentException;

/**
 * Auditable read-only ledger. An invoice is evidence of a claim, not evidence
 * of an integralization. Paid invoices and their payment transactions are one
 * movement, never two.
 */
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
    public function rows(?int $contactId = null, ?string $until = null): array
    {
        if ($contactId !== null && $contactId <= 0) {
            throw new InvalidArgumentException('Identificador de cooperado inválido.');
        }
        if ($until !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) || !checkdate((int) substr($until, 5, 2), (int) substr($until, 8, 2), (int) substr($until, 0, 4)))) {
            throw new InvalidArgumentException('Data limite inválida. Use AAAA-MM-DD.');
        }

        $companyId = (int) getenv('AKAUNTING_COMPANY_ID');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('Configure AKAUNTING_COMPANY_ID para evitar cruzamento entre empresas.');
        }
        $params = [
            'company_id' => $companyId,
            'category_id' => $this->settings->categoryId,
            'item_id' => $this->settings->itemId,
        ];
        $types = ['company_id' => ParameterType::INTEGER, 'category_id' => ParameterType::INTEGER, 'item_id' => ParameterType::INTEGER];
        $dateFilterT = '';
        $dateFilterP = '';
        $dateFilterD = '';
        if ($until !== null) {
            $params['end_of_day'] = $until . ' 23:59:59';
            $dateFilterT = ' AND t.paid_at <= :end_of_day';
            $dateFilterD = ' AND d.issued_at <= :end_of_day';
            $dateFilterP = ' AND pt.paid_at <= :end_of_day';
        }
        $entries = $this->accounting->executeQuery(
            "SELECT t.id, t.contact_id, c.name, c.tax_number, t.amount, t.description, t.document_id, t.currency_code, t.paid_at AS date, t.type, t.category_id, cat.name AS category_name, cat.code AS category_code
             FROM transactions t
             JOIN contacts c ON c.id = t.contact_id AND c.company_id = t.company_id
             LEFT JOIN categories cat ON cat.id = t.category_id AND cat.company_id = t.company_id
             WHERE (t.category_id = :category_id OR cat.code = 'capital-social-refund' OR LOWER(t.description) LIKE '%capital social%'
                    OR REPLACE(LOWER(t.description), ' ', '') LIKE '%capitalsocial%')
               AND (t.document_id IS NULL OR NOT EXISTS (
                 SELECT 1 FROM documents dx
                 WHERE dx.id = t.document_id AND dx.company_id = t.company_id
                   AND dx.deleted_at IS NULL AND (dx.category_id = :category_id OR EXISTS (
                     SELECT 1 FROM document_items dix
                     WHERE dix.document_id = dx.id AND dix.item_id = :item_id
                       AND dix.deleted_at IS NULL))
               ))
               AND t.deleted_at IS NULL AND t.contact_id IS NOT NULL AND t.company_id = :company_id" . $dateFilterT,
            $params,
            $types
        )->fetchAllAssociative();

        $sql = "SELECT d.id, d.contact_id, d.contact_name AS name, co.tax_number, d.type,
                       d.status, d.currency_code, d.amount AS document_amount, d.category_id, d.due_at,
                       COALESCE((SELECT SUM(di.total) FROM document_items di
                                 WHERE di.document_id = d.id AND di.item_id = :item_id
                                   AND di.deleted_at IS NULL), 0) AS capital_amount,
                       (SELECT COUNT(*) FROM document_items di
                        WHERE di.document_id = d.id AND di.item_id = :item_id
                          AND di.deleted_at IS NULL) AS capital_items,
                       (SELECT COUNT(*) FROM document_items di
                        WHERE di.document_id = d.id AND di.deleted_at IS NULL) AS item_count,
                       COALESCE((SELECT SUM(pt.amount) FROM transactions pt
                                 WHERE pt.document_id = d.id AND pt.company_id = d.company_id
                                   AND pt.deleted_at IS NULL
                                   AND pt.type = CASE WHEN d.type = 'bill' THEN 'expense' ELSE 'income' END
                                   AND pt.currency_code = d.currency_code" . $dateFilterP . "), 0) AS paid_amount,
                       (SELECT MAX(pt.paid_at) FROM transactions pt
                        WHERE pt.document_id = d.id AND pt.company_id = d.company_id
                          AND pt.deleted_at IS NULL
                                   AND pt.type = CASE WHEN d.type = 'bill' THEN 'expense' ELSE 'income' END
                                   AND pt.currency_code = d.currency_code" . $dateFilterP . ") AS paid_at,
                       -- Adiantamentos ja pagos e simultaneamente deduzidos da fatura
                       -- nao devem ser somados duas vezes no teste de quitacao.
                       COALESCE((SELECT SUM(pt.amount) FROM transactions pt
                                 WHERE pt.document_id = d.id AND pt.company_id = d.company_id
                                   AND pt.deleted_at IS NULL AND pt.type = 'expense'
                                   AND pt.currency_code = d.currency_code AND pt.category_id = 34" . $dateFilterP . "), 0) AS advance_paid,
                       COALESCE((SELECT -SUM(di.total) FROM document_items di
                                 WHERE di.document_id = d.id AND di.item_id = 31
                                   AND di.deleted_at IS NULL AND di.total < 0), 0) AS advance_deducted,
                       -- Diferenca explicada somente por linha avulsa de
                       -- Servico (item legado 8), posterior ao pagamento. INSS,
                       -- IRPF e outros descontos nao podem justificar excesso.
                       COALESCE((SELECT -SUM(di.total) FROM document_items di
                                 WHERE di.document_id = d.id AND di.item_id = 8
                                   AND di.deleted_at IS NULL AND di.total < 0
                                   AND di.created_at > (SELECT MAX(pt.paid_at) FROM transactions pt
                                     WHERE pt.document_id = d.id AND pt.company_id = d.company_id
                                       AND pt.deleted_at IS NULL
                                       AND pt.type = CASE WHEN d.type = 'bill' THEN 'expense' ELSE 'income' END
                                       AND pt.currency_code = d.currency_code" . $dateFilterP . ")), 0) AS post_payment_deductions
                FROM documents d
                JOIN contacts co ON co.id = d.contact_id AND co.company_id = d.company_id
                WHERE d.deleted_at IS NULL AND d.company_id = :company_id" . $dateFilterD . "
                  AND d.contact_id IS NOT NULL
                  AND (d.category_id = :category_id OR EXISTS (
                        SELECT 1 FROM document_items di
                        WHERE di.document_id = d.id AND di.item_id = :item_id
                          AND di.deleted_at IS NULL))
                  ORDER BY d.id";
        $documents = $this->accounting->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $canonical = [];
        foreach (array_merge($entries, $documents) as $record) {
            $key = self::identityKey($record);
            $canonical[$key] = min($canonical[$key] ?? (int) $record['contact_id'], (int) $record['contact_id']);
        }
        $rows = [];

        foreach ($entries as $entry) {
            // This source category has historically also been used for returns of
            // third-party cooperative capital and refunds of overpayments. Those
            // are not subscriptions by a LibreCode member.
            $description = mb_strtolower((string) $entry['description'], 'UTF-8');
            $looksLikeReturn = (bool) preg_match('/devolu|restitui|estorn|pag[oa] a maior/u', $description);
            // Entrada que restitui um investimento externo ou um pagamento a
            // maior nao e integralizacao de quota de cooperado.
            if ($entry['type'] === 'income' && $looksLikeReturn) {
                continue;
            }
            // A categoria "Conta Capital" representa participacao da LibreCode
            // em outra cooperativa, nao capital de seus membros.
            if ($entry['type'] === 'expense' && $entry['category_name'] === 'Conta Capital') {
                continue;
            }
            $income = $entry['type'] === 'income' && (int) $entry['category_id'] === $this->settings->categoryId
                && $entry['currency_code'] === 'BRL' && self::cents($entry['amount']) > 0 && !$looksLikeReturn;
            // A documented refund without a linked bill is still a capital
            // restitution, even if its historical expense category is generic.
            $legacyRefund = (int) $entry['category_id'] === 16
                && (bool) preg_match('/devolu[çc][ãa]o\s*de\s*capital\s*social/u', $description);
            // Dedicated refund category is an explicit business classification,
            // independent from the free-text description of a past transaction.
            $dedicatedRefund = (string) ($entry['category_code'] ?? '') === 'capital-social-refund';
            $refund = $entry['type'] === 'expense' && ($legacyRefund || $dedicatedRefund)
                && $entry['currency_code'] === 'BRL' && self::cents($entry['amount']) > 0
                && $entry['document_id'] === null;
            $reason = '';
            if ($entry['document_id'] !== null) {
                $income = false;
                $reason = 'Lançamento menciona capital social, mas a fatura vinculada não contém item/categoria de capital.';
            } elseif ($looksLikeReturn && !$refund) {
                $reason = 'Descrição de restituição/estorno: corrigir a classificação na origem antes de incluir no capital social.';
            } elseif (!$income && !$refund) {
                $reason = 'Movimentação possivelmente relacionada ao capital social, mas sem classificação confiável.';
            }
            $rows[] = $this->movement(
                'transaction',
                (int) $entry['id'],
                $canonical[self::identityKey($entry)],
                (string) $entry['name'],
                (string) $entry['date'],
                $income ? 'integralizacao' : ($refund ? 'devolucao' : 'revisao'),
                $income ? self::cents($entry['amount']) : ($refund ? -self::cents($entry['amount']) : 0),
                $reason,
                abs(self::cents($entry['amount'])),
                (string) $entry['currency_code']
            );
        }

        foreach ($documents as $doc) {
            $capital = (int) $doc['capital_items'] > 0
                ? self::cents($doc['capital_amount']) : self::cents($doc['document_amount']);
            $total = self::cents($doc['document_amount']);
            $paid = self::cents($doc['paid_amount']);
            $advancePaid = self::cents($doc['advance_paid']);
            $advanceDeducted = self::cents($doc['advance_deducted']);
            $postPaymentDeductions = self::cents($doc['post_payment_deductions']);
            $isBill = $doc['type'] === 'bill';
            $isInvoice = $doc['type'] === 'invoice';
            // Se o adiantamento foi pago e tambem abatido como item da mesma
            // fatura, somente o pagamento liquido posterior liquida a fatura.
            if ($isBill && $doc['status'] === 'paid' && $advancePaid > 0
                && $advancePaid === $advanceDeducted && $paid - $advancePaid === $total) {
                $paid -= $advancePaid;
            }
            // A settled zero-net production bill can withhold capital in full.
            // Its zero-value payment record proves settlement without a bank outflow.
            $nettedCapital = $isBill && $doc['status'] === 'paid' && $total === 0
                && $paid === 0 && $capital < 0 && (int) $doc['capital_items'] > 0
                && !empty($doc['paid_at']);
            $hasPayment = ($paid > 0 && !empty($doc['paid_at'])) || $nettedCapital;
            $fullPayment = $hasPayment && ($total <= 0 || $paid >= $total);
            $reason = '';
            $value = 0;
            $kind = 'revisao';
            $verifiedLateDeduction = false;

            if ($doc['currency_code'] !== 'BRL') {
                $reason = 'Moeda diferente de BRL: precisa de valor convertido e documentado.';
            } elseif (!$isBill && !$isInvoice) {
                $reason = 'Documento com tipo não reconhecido.';
            } elseif (!$hasPayment) {
                $reason = 'Fatura sem pagamento bancário vinculado até a data de corte.';
            } elseif (!$fullPayment) {
                $reason = 'Pagamento parcial: validar alocação do valor de capital social antes de confirmar.';
            } elseif ($isInvoice && $capital > 0) {
                $kind = 'integralizacao';
                $value = $capital;
            } elseif ($isBill && $capital > 0 && (int) $doc['capital_items'] === 0) {
                $reason = 'Fatura de saída classificada como entrada de capital: conferir tipo e item.';
            } elseif ($isBill && $capital > 0) {
                $kind = 'devolucao';
                $value = -$capital;
                if ((int) $doc['item_count'] > (int) $doc['capital_items']) {
                    $reason = 'Documento de saída misto: conferir se o item positivo representa devolução.';
                    $kind = 'revisao';
                    $value = 0;
                }
            } elseif ($isBill && $capital < 0) {
                // Negative capital item reduces the cooperative production payable.
                $kind = 'retencao';
                $value = -$capital;
            } else {
                $reason = 'Sinal ou natureza do item de capital social requer conferência.';
            }
            if ($total > 0 && $paid > $total + 1) {
                $excess = $paid - $total;
                // Reconhecer a retencao comprovada, sem esconder que a fatura
                // foi alterada depois de um pagamento valido. Nao usar esta
                // excecao para pagamentos em excesso sem causa verificavel.
                $verifiedLateDeduction = $isBill && $doc['status'] === 'paid'
                    && $kind === 'retencao' && $excess === $postPaymentDeductions;
                if ($verifiedLateDeduction) {
                    $reason = sprintf(
                        'Pagamento supera a fatura em R$ %.2f: deducao nao-capital adicionada apos o pagamento; conferir a alteracao documental.',
                        $excess / 100
                    );
                } else {
                    $kind = 'revisao';
                    $value = 0;
                    $reason = 'Pagamento associado supera o valor da fatura.';
                }
            }
            $rows[] = $this->movement(
                'document',
                (int) $doc['id'],
                $canonical[self::identityKey($doc)],
                (string) $doc['name'],
                (string) ($doc['paid_at'] ?: $doc['due_at']),
                $kind,
                $value,
                $reason,
                self::cents($doc['capital_amount']) !== 0 ? abs(self::cents($doc['capital_amount'])) : abs($capital),
                (string) $doc['currency_code'],
                $verifiedLateDeduction
            );
        }
        if ($contactId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['contact_id'] === $contactId));
        }
        usort($rows, static fn (array $a, array $b): int => [$a['name'], $a['date'], $a['table'], $a['id']] <=> [$b['name'], $b['date'], $b['table'], $b['id']]);
        return $rows;
    }

    /** @param array<string, mixed> $record */
    private static function identityKey(array $record): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($record['tax_number'] ?? ''));
        return in_array(strlen((string) $digits), [11, 14], true) && !preg_match('/^(\d)\1+$/', (string) $digits)
            ? 'tax:' . $digits : 'contact:' . (int) $record['contact_id'];
    }

    private static function cents(mixed $money): int
    {
        $text = (string) $money;
        // Fixed-decimal database amounts; never round unknown extra precision silently.
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $match)) {
            throw new \UnexpectedValueException('Valor financeiro inválido.');
        }
        if (strlen($match[3] ?? '') > 2 && trim(substr($match[3], 2), '0') !== '') {
            throw new \UnexpectedValueException('Precisão monetária maior que centavos: revisar o registro.');
        }
        $fraction = str_pad($match[3] ?? '', 3, '0');
        $base = ((int) $match[2]) * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$base;
        }
        return ($match[1] === '-') ? -$base : $base;
    }

    /** @return array<string, mixed> */
    private function movement(
        string $table,
        int $id,
        int $contactId,
        string $name,
        string $date,
        string $kind,
        int $amountCents,
        string $reason,
        ?int $candidateCents = null,
        string $currency = 'BRL',
        bool $confirmedWithDocumentWarning = false
    ): array {
        return [
            'contact_id' => $contactId,
            'name' => $name,
            'date' => substr($date, 0, 10),
            'kind' => $kind,
            'natureza' => match ($kind) {
                'integralizacao' => 'Integralização',
                'retencao' => 'Retenção na produção',
                'devolucao' => 'Devolução',
                default => 'Conferência necessária',
            },
            'status' => ($reason === '' || $confirmedWithDocumentWarning) ? 'confirmado' : 'revisar',
            'amount' => $amountCents / 100,
            'candidate_amount' => ($candidateCents ?? abs($amountCents)) / 100,
            'currency' => $currency,
            'reason' => $reason,
            'table' => $table,
            'id' => $id,
        ];
    }
}
