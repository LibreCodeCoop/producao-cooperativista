<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/migrations',
        __DIR__ . '/src',
        __DIR__ . '/tests/php',
    ])
    ->withPhpSets(php85: true)
    // Dynamic Doctrine entities and injected services must not be made readonly
    // automatically without proving that hydration and mocking still work.
    ->withSkip([
        ReadOnlyPropertyRector::class,
        // The inferred signatures can be wrong on DBAL query builders and
        // dynamic document calls. Preserve explicit parameter binding types.
        RemoveExtraParametersRector::class,
    ]);
