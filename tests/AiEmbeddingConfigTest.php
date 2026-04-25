<?php

declare(strict_types=1);

namespace Tests\Ai;

use EzPhp\Ai\AiEmbeddingConfig;

/**
 * @covers \EzPhp\Ai\AiEmbeddingConfig
 */
final class AiEmbeddingConfigTest extends TestCase
{
    public function testModelAccessor(): void
    {
        $config = new AiEmbeddingConfig('text-embedding-3-small');

        $this->assertSame('text-embedding-3-small', $config->model());
    }

    public function testDimensionsDefaultsToNull(): void
    {
        $config = new AiEmbeddingConfig('text-embedding-3-small');

        $this->assertNull($config->dimensions());
    }

    public function testDimensionsAccessor(): void
    {
        $config = new AiEmbeddingConfig('text-embedding-3-large', 256);

        $this->assertSame(256, $config->dimensions());
    }
}
