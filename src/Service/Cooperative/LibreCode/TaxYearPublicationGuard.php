<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative\LibreCode;

use DateTimeInterface;
use DomainException;

/**
 * Fail closed at obvious IRRF table-year boundaries until payment-period
 * tax treatment has been explicitly homologated. Not a tax calculator.
 */
final readonly class TaxYearPublicationGuard
{
    public function assertSafeForIndividual(
        DateTimeInterface $workMonth,
        DateTimeInterface $expectedPayment
    ): void {
        if ($workMonth->format('Y') === $expectedPayment->format('Y')) {
            return;
        }

        throw new DomainException(
            'Publicação fiscal bloqueada: o pagamento previsto está em ano civil diferente da '
            . 'competência trabalhada, mas o IRRF ainda utiliza a tabela do mês trabalhado. '
            . 'Confirme o período tributário e o pagamento efetivo antes de emitir documentos.'
        );
    }
}
