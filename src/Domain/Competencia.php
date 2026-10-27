<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Domain;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable monthly partition key. A cooperative production competence is
 * distinct from the following Akaunting financial transaction month.
 */
final readonly class Competencia implements \Stringable
{
    private function __construct(private DateTimeImmutable $start)
    {
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value)) {
            throw new InvalidArgumentException('Competência inválida. Informe ano e mês no formato YYYY-MM.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m', $value);
        if (!$start instanceof DateTimeImmutable || $start->format('Y-m') !== $value) {
            throw new InvalidArgumentException('Competência inválida. Informe ano e mês no formato YYYY-MM.');
        }
        return new self($start);
    }

    public function start(): DateTime
    {
        return DateTime::createFromImmutable($this->start);
    }

    public function endExclusive(): DateTimeImmutable
    {
        return $this->start->modify('first day of next month');
    }

    public function financialMonth(): string
    {
        return $this->endExclusive()->format('Y-m');
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->start->format('Y-m');
    }
}
