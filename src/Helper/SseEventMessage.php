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
