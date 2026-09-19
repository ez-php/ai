<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\EmbeddingClientInterface;

/**
 * An embedding driver that always returns empty vectors without making any network calls.
 *
 * Intended for testing and local development, mirroring NullDriver's role for
 * AiClientInterface. Default when no `ai.embedding_driver` is configured.
 *
 * @package EzPhp\Ai\Driver
 */
final class NullEmbeddingDriver implements EmbeddingClientInterface
{
    /**
     * {@inheritDoc}
     */
    public function embed(string $input, ?string $model = null): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function embedBatch(array $inputs, ?string $model = null): array
    {
        return array_fill(0, count($inputs), []);
    }
}
