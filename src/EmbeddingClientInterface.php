<?php

declare(strict_types=1);

namespace EzPhp\Ai;

/**
 * Contract for AI embedding drivers.
 *
 * @package EzPhp\Ai
 */
interface EmbeddingClientInterface
{
    /**
     * Embed a text input and return the vector as a float array.
     *
     * @param string      $input The text to embed.
     * @param string|null $model Override the driver's default embedding model.
     *
     * @return float[]
     *
     * @throws AiRequestException On HTTP error or malformed response.
     */
    public function embed(string $input, ?string $model = null): array;

    /**
     * Embed multiple text inputs in a single call and return a list of vectors.
     *
     * Drivers that support native batching (e.g. OpenAI) send a single HTTP
     * request. Drivers without batch API support call embed() per input.
     *
     * @param list<string> $inputs One or more texts to embed.
     * @param string|null  $model  Override the driver's default embedding model.
     *
     * @return list<float[]> One vector per input, in the same order.
     *
     * @throws AiRequestException On HTTP error or malformed response.
     */
    public function embedBatch(array $inputs, ?string $model = null): array;
}
