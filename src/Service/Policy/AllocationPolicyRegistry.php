<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Policy;

use InvalidArgumentException;

final class AllocationPolicyRegistry
{
    /** @var array<string, AllocationPolicy> */
    private array $policies = [];

    /** @param iterable<AllocationPolicy> $policies */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(iterable $policies)
    {
        foreach ($policies as $policy) {
            if ($policy->id() === '' || isset($this->policies[$policy->id()])) {
                throw new InvalidArgumentException('Política de distribuição duplicada ou inválida.');
            }
            $this->policies[$policy->id()] = $policy;
        }
        if ($this->policies === []) {
            throw new InvalidArgumentException('Nenhuma política de distribuição configurada.');
        }
    }

    public function get(string $id): AllocationPolicy
    {
        return $this->policies[$id]
            ?? throw new InvalidArgumentException(sprintf('Política de distribuição desconhecida: %s.', $id));
    }
}
