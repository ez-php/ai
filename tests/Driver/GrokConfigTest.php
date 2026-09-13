<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\GrokConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Ai\TestCase;

#[CoversClass(GrokConfig::class)]
final class GrokConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new GrokConfig('xai-key');

        $this->assertSame('xai-key', $config->apiKey());
        $this->assertSame('grok-3-mini', $config->model());
        $this->assertSame('https://api.x.ai', $config->baseUrl());
    }

    public function testDefaultConstantsMatchDefaultValues(): void
    {
        $this->assertSame(GrokConfig::DEFAULT_MODEL, (new GrokConfig('xai-key'))->model());
        $this->assertSame(GrokConfig::DEFAULT_BASE_URL, (new GrokConfig('xai-key'))->baseUrl());
    }

    public function testCustomValues(): void
    {
        $config = new GrokConfig('xai-key', 'grok-4', 'https://proxy.example.com');

        $this->assertSame('grok-4', $config->model());
        $this->assertSame('https://proxy.example.com', $config->baseUrl());
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $config = new GrokConfig('xai-key', baseUrl: 'https://proxy.example.com/');

        $this->assertSame('https://proxy.example.com', $config->baseUrl());
    }
}
