<?php

declare(strict_types=1);

namespace Tests\Ai\Tool;

use EzPhp\Ai\Tool\ToolDefinition;
use Tests\Ai\TestCase;

/**
 * @covers \EzPhp\Ai\Tool\ToolDefinition
 */
final class ToolDefinitionTest extends TestCase
{
    public function testGetters(): void
    {
        $params = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];
        $tool = new ToolDefinition('get_weather', 'Returns the weather for a city.', $params);

        $this->assertSame('get_weather', $tool->name());
        $this->assertSame('Returns the weather for a city.', $tool->description());
        $this->assertSame($params, $tool->parameters());
    }

    public function testParametersDefaultToEmptyArray(): void
    {
        $tool = new ToolDefinition('ping', 'Pings the server.');

        $this->assertSame([], $tool->parameters());
    }
}
