<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Helper;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Forward only application messages to the active response stream, preventing
 * ORM queries, URLs and other integration diagnostics from reaching the browser.
 */
class SseLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected PsrLogMessageProcessor $processor,
        protected Sse $sse,
    ) {
        parent::__construct(Level::Info);
        $this->pushProcessor($processor);
    }

    protected function write(LogRecord $record): void
    {
        if (!in_array($record->channel, ['app', 'sse'], true)) {
            return;
        }

        $event = SseEventMessage::fromLogRecord($record->message, $record->level->name);
        // Detailed errors and exception payloads are for the server log only.
        if ($event['event'] === 'error' || $event['event'] === 'critical') {
            return;
        }

        $this->sse->send($event['event'], $event['data']);
    }
}
