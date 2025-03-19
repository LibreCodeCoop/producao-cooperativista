<?php

/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace App\Helper;

class Sse
{
    private bool $started = false;

    protected function init(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        $this->obEnd();
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header("Content-Type: text/event-stream");
        flush();
    }

    /**
     * Clean PHP output buffer before start
     */
    protected function obEnd(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    /**
     * Send a message to the client
     *
     * @throws \BadMethodCallException if only one parameter is given, a typeless message will be send with that parameter as data
     */
    public function send(string $event, ?string $data = null): void
    {
        if ($data and !preg_match('/^[A-Za-z0-9_]+$/', $event)) {
            throw new \BadMethodCallException('Type needs to be alphanumeric ('. $event .')');
        }
        $this->init();
        if (is_null($data)) {
            $data = $event;
            $event = null;
        }
        if ($event) {
            echo 'event: ' . $event . PHP_EOL;
        }
        echo 'data: ' . json_encode($data, JSON_HEX_TAG | JSON_THROW_ON_ERROR) . PHP_EOL;
        echo PHP_EOL;
        flush();
    }
}
