<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\CooperativeProduction\Domain\MemberRead;

final class MemberController extends Controller
{
    public function index(MemberRead $reader): JsonResponse
    {
        return response()->json($reader->history())->header('Cache-Control', 'private, no-store');
    }
}
