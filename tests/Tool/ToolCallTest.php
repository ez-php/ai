<?php

declare(strict_types=1);

namespace Tests\Ai\Tool;

use EzPhp\Ai\Tool\ToolCall;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Tool\ToolCall
 */
final class ToolCallTest extends TestCase
{
    public function testGetters(): void
    {
        $args = ['city' => 'Paris', 'unit' => 'celsius'];
        $call = new ToolCall('call_abc123', 'get_weather', $args);

        $this->assertSame('call_abc123', $call->id());
        $this->assertSame('get_weather', $call->name());
        $this->assertSame($args, $call->arguments());
    }

    public function testEmptyArguments(): void
    {
        $call = new ToolCall('call_001', 'ping', []);

        $this->assertSame([], $call->arguments());
    }
}
