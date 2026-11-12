<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CooperativeProduction\Http\Controllers\MemberController;

// Akaunting's API middleware authenticates each user, checks read-api,
// identifies the active company and rejects disabled accounts.
// The domain service independently verifies company membership + member link.
Route::group([
    'prefix' => trim((string) config('api.prefix', 'api'), '/') . '/v1/cooperative-production',
    'middleware' => config('api.middleware', ['api']),
    'as' => 'api.cooperative-production.',
], static function (): void {
    Route::get('me/history', [MemberController::class, 'index'])->name('members.history');
});
