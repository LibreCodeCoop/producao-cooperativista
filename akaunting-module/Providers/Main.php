<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\CooperativeProduction\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The first module slice is calculation-only; no finance-writing routes or
 * mutations are registered until the native authorization gate is verified.
 */
final class Main extends ServiceProvider
{
    public function register(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'cooperative-production');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
