<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Helper;

/**
 * Normalizes application events before sending them to a browser.
 */
final class SseEventMessage
{
    /**
     * Streams only explicitly authored progress events. Arbitrary application
     * log messages may contain customer identifiers, names, financial amounts
     * or integration details and must remain in restricted server logs.
     *
     * @return array{event: string, data: string}|null
     */
    public static function fromExplicitProgressRecord(string $message): ?array
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)
            || array_keys($payload) !== ['event', 'data']
            || !is_string($payload['event'])
            || !in_array($payload['event'], ['info', 'warning', 'alert', 'done', 'failed'], true)
            || !is_string($payload['data'])) {
            return null;
        }

        return ['event' => $payload['event'], 'data' => $payload['data']];
    }

    /**
     * @return array{event: string, data: string}
     */
    public static function fromLogRecord(string $message, string $level): array
    {
        $payload = json_decode($message, true);
        if (is_array($payload)
            && is_string($payload['event'] ?? null)
            && preg_match('/^[A-Za-z0-9_]+$/', $payload['event'])
            && is_string($payload['data'] ?? null)) {
            return ['event' => strtolower($payload['event']), 'data' => $payload['data']];
        }

        return ['event' => strtolower($level), 'data' => $message];
    }
}
