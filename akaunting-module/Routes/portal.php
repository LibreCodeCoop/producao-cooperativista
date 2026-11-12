<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CooperativeProduction\Http\Controllers\PortalController;

// Akaunting's portal group requires an authenticated, company-scoped user.
// All financial record access is then independently scoped by MemberRead.
Route::portal('cooperative-production', static function (): void {
    Route::get('history', [PortalController::class, 'history'])->name('history');
});
