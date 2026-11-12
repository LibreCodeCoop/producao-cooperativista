<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\CooperativeProduction\Domain\MemberRead;

final class PortalController extends Controller
{
    public function history(Request $request, MemberRead $reader): Response
    {
        $history = $reader->history($request->query('from'), $request->query('to'));

        return response()
            ->view('cooperative-production::portal.history', ['records' => $history['data'], 'filters' => $history['filters']])
            ->header('Cache-Control', 'private, no-store');
    }
}
