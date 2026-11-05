<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\Competencia;
use DomainException;
use RuntimeException;

/**
 * Verify an existing archived input bundle without touching source systems.
 * This detects accidental changes; not a substitute for signed external storage.
 */
final readonly class InputBundleVerifier
{
    /** @psalm-suppress PossiblyUnusedMethod Instantiated through Symfony DI and separate tests. */
    public function __construct(private InputManifestBuilder $builder)
    {
    }

    /** @return array{competencia: string, sha256: string} */
    public function verify(string $file, string $auditDirectory): array
    {
        $root = realpath($auditDirectory);
        $path = realpath($file);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Arquivo de auditoria inexistente ou fora da pasta privada de auditoria.');
        }
        $payload = file_get_contents($path);
        if (!is_string($payload)) {
            throw new RuntimeException('Não foi possível ler o bundle confidencial de auditoria.');
        }
        try {
            $bundle = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException('Bundle de auditoria não contém JSON válido.', 0, $exception);
        }
        if (!is_array($bundle) || !is_array($bundle['manifest'] ?? null)
            || !is_array($bundle['sources'] ?? null)
            || !is_array($bundle['rules'] ?? null)
            || !is_string($bundle['revision'] ?? null)
            || !is_string($bundle['manifest']['competencia'] ?? null)) {
            throw new DomainException('Bundle de auditoria não contém os dados obrigatórios.');
        }

        $competence = Competencia::fromString($bundle['manifest']['competencia']);
        $rebuilt = $this->builder->build(
            $competence,
            $bundle['sources'],
            $bundle['rules'],
            $bundle['revision'],
        );
        if ($rebuilt !== $bundle['manifest']) {
            throw new DomainException('Integridade do bundle de auditoria inválida. Confira a fonte e a cópia original.');
        }
        return ['competencia' => (string) $competence, 'sha256' => $rebuilt['sha256']];
    }
}
