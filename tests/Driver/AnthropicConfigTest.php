<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\AnthropicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Ai\TestCase;

#[CoversClass(AnthropicConfig::class)]
final class AnthropicConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new AnthropicConfig('sk-ant-test');

        $this->assertSame('sk-ant-test', $config->apiKey());
        $this->assertSame('claude-sonnet-4-6', $config->model());
        $this->assertSame('2023-06-01', $config->apiVersion());
    }

    public function testDefaultConstantsMatchDefaultValues(): void
    {
        $this->assertSame(AnthropicConfig::DEFAULT_MODEL, (new AnthropicConfig('sk-ant-test'))->model());
        $this->assertSame(AnthropicConfig::DEFAULT_API_VERSION, (new AnthropicConfig('sk-ant-test'))->apiVersion());
    }

    public function testCustomValues(): void
    {
        $config = new AnthropicConfig('sk-ant-test', 'claude-opus-5', '2024-01-01');

        $this->assertSame('claude-opus-5', $config->model());
        $this->assertSame('2024-01-01', $config->apiVersion());
    }

    public function testBaseUrlConstantIsFixed(): void
    {
        $this->assertSame('https://api.anthropic.com', AnthropicConfig::BASE_URL);
    }
}
