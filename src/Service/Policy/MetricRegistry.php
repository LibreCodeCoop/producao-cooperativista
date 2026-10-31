<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use InvalidArgumentException;

final class MetricRegistry
{
    /** @var array<string, MetricStrategy> */
    private array $strategies = [];

    /** @param iterable<MetricStrategy> $strategies */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(iterable $strategies)
    {
        foreach ($strategies as $strategy) {
            if ($strategy->id() === '' || isset($this->strategies[$strategy->id()])) {
                throw new InvalidArgumentException('Métrica duplicada ou inválida.');
            }
            $this->strategies[$strategy->id()] = $strategy;
        }
        if ($this->strategies === []) {
            throw new InvalidArgumentException('Nenhuma métrica de produção registrada.');
        }
    }

    public function get(string $id): MetricStrategy
    {
        return $this->strategies[$id] ?? throw new InvalidArgumentException(
            sprintf('Métrica de produção desconhecida: %s.', $id)
        );
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function available(): array
    {
        return array_keys($this->strategies);
    }
}
