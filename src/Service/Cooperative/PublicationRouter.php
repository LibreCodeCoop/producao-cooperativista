<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative;

use InvalidArgumentException;
use LogicException;

/** Fail closed if no reviewed financial publisher exists for a profile. */
final class PublicationRouter
{
    /** @var array<string, ProductionPublisher> */
    private array $publishers = [];

    /** @param iterable<ProductionPublisher> $publishers */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function __construct(iterable $publishers, private readonly ProfileRegistry $profiles)
    {
        foreach ($publishers as $publisher) {
            if ($publisher->profileId() === '' || isset($this->publishers[$publisher->profileId()])) {
                throw new InvalidArgumentException('Publicador financeiro duplicado ou inválido.');
            }
            $this->publishers[$publisher->profileId()] = $publisher;
        }
    }

    /**
     * @param array<string, \App\Service\Cooperado> $members
     * @param list<array<string, mixed>> $work
     */
    public function publish(array $members, array $work): void
    {
        $profile = $this->profiles->active();
        $publisher = $this->publishers[$profile->id()] ?? null;
        if ($publisher === null) {
            throw new LogicException(
                'Não há publicador financeiro homologado para o perfil selecionado. Nenhum documento será emitido.'
            );
        }
        $publisher->publish($members, $work);
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Symfony DI or service registry runtime entry point. */
    public function available(): array
    {
        return array_keys($this->publishers);
    }
}
