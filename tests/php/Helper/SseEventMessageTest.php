<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\SseEventMessage;
use PHPUnit\Framework\TestCase;

final class SseEventMessageTest extends TestCase
{
    public function testFailedEventsArePreserved(): void
    {
        $this->assertSame(
            ['event' => 'failed', 'data' => 'Erro no processamento'],
            SseEventMessage::fromLogRecord('{"event":"failed","data":"Erro no processamento"}', 'info')
        );
    }

    public function testUnrelatedJsonDoesNotProduceAnInvalidEvent(): void
    {
        $this->assertSame(
            ['event' => 'info', 'data' => '{"customer":"1234"}'],
            SseEventMessage::fromLogRecord('{"customer":"1234"}', 'INFO')
        );
    }

    public function testDoesNotPermitSpecialCharactersAsEventName(): void
    {
        $this->assertSame(
            ['event' => 'error', 'data' => '{"event":"<script>","data":"teste"}'],
            SseEventMessage::fromLogRecord('{"event":"<script>","data":"teste"}', 'ERROR')
        );
    }
}
