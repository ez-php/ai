<?php

declare(strict_types=1);

namespace EzPhp\Ai;

/**
 * Configuration value object for an embedding request.
 *
 * Carries the target model and an optional output dimension limit.
 * Dimension truncation is supported by OpenAI (text-embedding-3-* models);
 * Gemini ignores the dimensions field.
 *
 * @package EzPhp\Ai
 */
final readonly class AiEmbeddingConfig
{
    /**
     * @param string   $model      Embedding model identifier.
     * @param int|null $dimensions Optional output vector length (truncation); provider-dependent.
     */
    public function __construct(
        private string $model,
        private ?int $dimensions = null,
    ) {
    }

    /**
     * @return string
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return int|null
     */
    public function dimensions(): ?int
    {
        return $this->dimensions;
    }
}
