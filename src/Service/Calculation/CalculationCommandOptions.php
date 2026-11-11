<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Calculation;

/**
 * Keep the web action aligned with make:producao's supported console options.
 * A preview must not request an implicit write to either database.
 */
final class CalculationCommandOptions
{
    /** @return array<string, string|bool> */
    public static function forWeb(
        string $competence,
        string $download,
        bool $publish,
        bool $importOnly = false,
        string $revision = '',
        string $excluded = '',
    ): array {
        return [
            '--ano-mes' => $competence,
            '--baixar-dados' => $download,
            '--atualiza-producao' => $publish,
            '--import-only' => $importOnly,
            '--expected-revision' => $revision,
            '--omit-member-ids' => $excluded,
        ];
    }
}
