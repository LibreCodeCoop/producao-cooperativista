<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\Sse;
use App\Helper\SseLogHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;

final class SseLogHandlerTest extends TestCase
{
    public function testSuppressesDoctrineLogsAndRawErrorMessages(): void
    {
        $sse = new class () extends Sse {
            public array $events = [];
            public function send(string $event, ?string $data = null): void
            {
                $this->events[] = [$event, $data];
            }
        };
        $handler = new SseLogHandler(new PsrLogMessageProcessor(), $sse);
        foreach ([
            ['doctrine', Level::Info, 'SELECT client CPF 123'],
            ['app', Level::Error, 'password private-token'],
            ['app', Level::Info, '{"event":"done","data":"Concluído"}'],
        ] as [$channel, $level, $message]) {
            $handler->handle(new LogRecord(new \DateTimeImmutable(), $channel, $level, $message));
        }
        self::assertSame([['done', 'Concluído']], $sse->events);
    }
}
