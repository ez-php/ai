<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\GeminiConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Ai\TestCase;

#[CoversClass(GeminiConfig::class)]
final class GeminiConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new GeminiConfig('gemini-key');

        $this->assertSame('gemini-key', $config->apiKey());
        $this->assertSame('gemini-2.0-flash', $config->model());
    }

    public function testDefaultConstantMatchesDefaultValue(): void
    {
        $this->assertSame(GeminiConfig::DEFAULT_MODEL, (new GeminiConfig('gemini-key'))->model());
    }

    public function testCustomModel(): void
    {
        $config = new GeminiConfig('gemini-key', 'gemini-2.5-pro');

        $this->assertSame('gemini-2.5-pro', $config->model());
    }

    public function testBaseUrlConstantIsFixed(): void
    {
        $this->assertSame('https://generativelanguage.googleapis.com', GeminiConfig::BASE_URL);
    }
}
