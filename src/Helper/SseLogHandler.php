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

namespace ProducaoCooperativista\Helper;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use Monolog\Level;
use Monolog\LogRecord;

class SseLogHandler extends HandlerWrapper
{
    private array $filter = [
        '/doctrine/',
    ];

    public function __construct(
        protected HandlerInterface $handler,
        protected Sse $sse,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level->value >= Level::Info->value) {
            if (!$this->isBlocked()) {
                if (json_validate($record->message)) {
                    $message = json_decode($record->message);
                    $this->sse->send(strtolower($message->event), $message->data);
                } else {
                    $this->sse->send(strtolower($record->level->name), $record->message);
                }
            }
        }
        return $this->handler->handle($record);
    }

    private function isBlocked(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $row) {
            foreach ($this->filter as $pattern) {
                if (preg_match($pattern, $row['file'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
