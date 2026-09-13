<?php

declare(strict_types=1);

namespace Tests\Ai\Driver;

use EzPhp\Ai\Driver\MistralConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Ai\TestCase;

#[CoversClass(MistralConfig::class)]
final class MistralConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new MistralConfig('mistral-key');

        $this->assertSame('mistral-key', $config->apiKey());
        $this->assertSame('mistral-small-latest', $config->model());
        $this->assertSame('https://api.mistral.ai', $config->baseUrl());
    }

    public function testDefaultConstantsMatchDefaultValues(): void
    {
        $this->assertSame(MistralConfig::DEFAULT_MODEL, (new MistralConfig('mistral-key'))->model());
        $this->assertSame(MistralConfig::DEFAULT_BASE_URL, (new MistralConfig('mistral-key'))->baseUrl());
    }

    public function testCustomValues(): void
    {
        $config = new MistralConfig('mistral-key', 'mistral-large-latest', 'https://self-hosted.example.com');

        $this->assertSame('mistral-large-latest', $config->model());
        $this->assertSame('https://self-hosted.example.com', $config->baseUrl());
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $config = new MistralConfig('mistral-key', baseUrl: 'https://self-hosted.example.com/');

        $this->assertSame('https://self-hosted.example.com', $config->baseUrl());
    }
}
