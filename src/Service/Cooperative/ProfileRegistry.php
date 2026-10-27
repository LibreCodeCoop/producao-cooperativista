<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

use InvalidArgumentException;
use LogicException;

/** Resolves a cooperative's explicit deployment profile, never from user input. */
final class ProfileRegistry
{
    /** @var array<string, CooperativeProfile> */
    private array $profiles = [];

    /** @param iterable<CooperativeProfile> $profiles */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(iterable $profiles, private readonly ?string $configuredId = null)
    {
        foreach ($profiles as $profile) {
            if (!$profile instanceof CooperativeProfile || $profile->id() === ''
                || isset($this->profiles[$profile->id()])) {
                throw new InvalidArgumentException('Identificador de perfil de cooperativa inválido ou duplicado.');
            }
            $this->profiles[$profile->id()] = $profile;
        }
        if ($this->profiles === []) {
            throw new LogicException('Nenhum perfil cooperativo disponível.');
        }
    }

    /** Look up a named policy for read-only simulations. */
    public function get(string $id): CooperativeProfile
    {
        return $this->profiles[$id]
            ?? throw new InvalidArgumentException(sprintf('Perfil cooperativo não registrado: %s.', $id));
    }

    public function active(): CooperativeProfile
    {
        $id = $this->configuredId ?? ((string) (getenv('COOPERATIVE_PROFILE') ?: 'librecode'));
        return $this->get($id);
    }

    public function requireLibreCodeSettlement(): void
    {
        if (!$this->active()->permitsLibreCodeSettlement()) {
            throw new LogicException(
                'O perfil selecionado não possui política de emissão e retenções homologada. ' .
                'A geração de documentos no Akaunting foi bloqueada; utilize uma prévia específica do perfil.'
            );
        }
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function available(): array
    {
        return array_keys($this->profiles);
    }
}
