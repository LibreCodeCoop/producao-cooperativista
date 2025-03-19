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

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerTrait;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

class SseLogHandler extends AbstractProcessingHandler
{
    use ProcessableHandlerTrait;
    private array $filter = [
        '/doctrine/',
    ];

    public function __construct(
        protected HandlerInterface $handler,
        protected PsrLogMessageProcessor $processor,
        protected Sse $sse,
    ) {
        $this->pushProcessor($processor);
    }

    protected function write(LogRecord $record): void
    {
        if ($record->level->value < Level::Info->value) {
            return;
        }
        if ($this->isBlocked()) {
            return;
        }
        if ($this->isCli()) {
            return;
        }
        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }
        if (json_validate($record->message)) {
            $message = json_decode($record->message);
            $this->sse->send(strtolower($message->event), $message->data);
        } else {
            $this->sse->send(strtolower($record->level->name), $record->message);
        }
    }

    private function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    private function isBlocked(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $row) {
            foreach ($this->filter as $pattern) {
                if (isset($row['file']) && preg_match($pattern, $row['file'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
