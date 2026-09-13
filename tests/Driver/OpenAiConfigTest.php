<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\OpenAiConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Ai\TestCase;

#[CoversClass(OpenAiConfig::class)]
final class OpenAiConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new OpenAiConfig('sk-test');

        $this->assertSame('sk-test', $config->apiKey());
        $this->assertSame('gpt-4o-mini', $config->model());
        $this->assertSame('https://api.openai.com', $config->baseUrl());
    }

    public function testDefaultConstantsMatchDefaultValues(): void
    {
        $this->assertSame(OpenAiConfig::DEFAULT_MODEL, (new OpenAiConfig('sk-test'))->model());
        $this->assertSame(OpenAiConfig::DEFAULT_BASE_URL, (new OpenAiConfig('sk-test'))->baseUrl());
    }

    public function testCustomValues(): void
    {
        $config = new OpenAiConfig('sk-test', 'gpt-4o', 'https://proxy.example.com');

        $this->assertSame('gpt-4o', $config->model());
        $this->assertSame('https://proxy.example.com', $config->baseUrl());
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $config = new OpenAiConfig('sk-test', baseUrl: 'https://proxy.example.com/');

        $this->assertSame('https://proxy.example.com', $config->baseUrl());
    }
}
