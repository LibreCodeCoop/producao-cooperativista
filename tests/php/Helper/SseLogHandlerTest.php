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
            ['app', Level::Info, 'Produção de Pessoa, CPF 11122233344, R$ 999.000,00'],
            ['app', Level::Warning, 'Erro ao buscar NFS-e, identificador fiscal 11122233344'],
            ['app', Level::Info, '{"customer":"11122233344","amount":"1000.00"}'],
            ['app', Level::Info, '{"event":"info","data":"Etapa de validação iniciada"}'],
            ['app', Level::Info, '{"event":"done","data":"Concluído"}'],
        ] as [$channel, $level, $message]) {
            $handler->handle(new LogRecord(new \DateTimeImmutable(), $channel, $level, $message));
        }
        self::assertSame([['info', 'Etapa de validação iniciada'], ['done', 'Concluído']], $sse->events);
    }
}
