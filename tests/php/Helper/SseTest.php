<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\Sse;
use PHPUnit\Framework\TestCase;

final class SseTest extends TestCase
{
    public function testFormatProducesPureSseWithoutHeaders(): void
    {
        self::assertSame(
            "event: failed\ndata: \"Algo falhou\"\n\n",
            Sse::format('failed', 'Algo falhou')
        );
    }

    public function testRejectsNewlineInjectionInEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sse::format("done\ndata: malicious", 'value');
    }
}
