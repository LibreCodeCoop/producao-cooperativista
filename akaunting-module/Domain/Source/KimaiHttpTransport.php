<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Modules\CooperativeProduction\Domain\Source;

use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Only GET is used. Credentials must come from trusted server configuration. */
final readonly class KimaiHttpTransport
{
    public function __construct(private string $baseUrl, private string $bearerToken)
    {
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || (isset($parts['user']) || isset($parts['pass']))
            || isset($parts['query']) || isset($parts['fragment'])
            || trim($this->bearerToken) === '') {
            throw new DomainException('Kimai requires a configured HTTPS API URL and bearer token.');
        }
    }

    /**
     * @param array<string, int|string|null> $query
     * @return array{records: mixed, total_pages: ?int}
     */
    public function fetchPage(array $query): array
    {
        $response = Http::withToken($this->bearerToken)
            ->acceptJson()
            ->timeout(30)
            ->get(rtrim($this->baseUrl, '/') . '/timesheets', array_filter(
                $query,
                static fn (mixed $value): bool => $value !== null,
            ));
        // Legacy Kimai ends an unadvertised pagination sequence with 404.
        // Only a page after the first can be interpreted as end-of-list.
        if ($response->status() === 404 && ($query['page'] ?? 1) > 1) {
            return ['records' => [], 'total_pages' => null];
        }
        if (!$response->successful()) {
            // Never include the remote body, request headers or secret URL.
            throw new RuntimeException('Kimai read failed (HTTP ' . $response->status() . ').');
        }
        $header = trim((string) $response->header('X-Total-Pages'));
        if ($header !== '' && preg_match('/^[0-9]+$/D', $header) !== 1) {
            throw new DomainException('Invalid Kimai pagination header.');
        }

        return [
            'records' => $response->json(),
            'total_pages' => $header === '' ? null : (int) $header,
        ];
    }
}
