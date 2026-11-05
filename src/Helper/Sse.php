<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Helper;

/**
 * The StreamedResponse owns HTTP headers. SSE must write only event payloads:
 * header() in this class caused "headers already sent" in production.
 */
class Sse
{
    public static function format(string $event, ?string $data = null): string
    {
        if ($data === null) {
            $data = $event;
            $event = 'message';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $event)) {
            throw new \InvalidArgumentException('Invalid SSE event name.');
        }

        return sprintf(
            "event: %s\ndata: %s\n\n",
            strtolower($event),
            json_encode($data, JSON_HEX_TAG | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    public function send(string $event, ?string $data = null): void
    {
        echo self::format($event, $data);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
