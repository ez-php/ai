<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\EmbeddingClientInterface;
use EzPhp\HttpClient\HttpClient;

/**
 * Embedding driver for the Google Gemini embedContent API.
 *
 * Calls POST /v1beta/models/{model}:embedContent and returns the float vector
 * from embedding.values.
 *
 * @package EzPhp\Ai\Driver
 */
final class GeminiEmbeddingDriver implements EmbeddingClientInterface
{
    public const string DEFAULT_EMBEDDING_MODEL = 'text-embedding-004';

    /**
     * @param HttpClient   $http   Injected HTTP client; use FakeTransport in tests.
     * @param GeminiConfig $config Driver configuration.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly GeminiConfig $config,
    ) {
    }

    /**
     * Embed a text input using the Gemini embedContent endpoint.
     *
     * @param string      $input The text to embed.
     * @param string|null $model Override the default embedding model.
     *
     * @return float[]
     *
     * @throws AiRequestException On HTTP error or malformed response.
     */
    public function embed(string $input, ?string $model = null): array
    {
        $resolvedModel = $model ?? self::DEFAULT_EMBEDDING_MODEL;
        $url = sprintf(
            '%s/v1beta/models/%s:embedContent?key=%s',
            GeminiConfig::BASE_URL,
            $resolvedModel,
            $this->config->apiKey(),
        );

        $body = ['content' => ['parts' => [['text' => $input]]]];

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode($body))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return $this->parseResponse($httpResponse->body());
    }

    /**
     * Embed multiple texts by issuing one embedContent call per input.
     *
     * Gemini's embedContent endpoint is single-input only; this method loops.
     *
     * @param list<string> $inputs
     * @param string|null  $model
     *
     * @return list<float[]>
     *
     * @throws AiRequestException On HTTP error or malformed response.
     */
    public function embedBatch(array $inputs, ?string $model = null): array
    {
        $results = [];

        foreach ($inputs as $input) {
            $results[] = $this->embed($input, $model);
        }

        return $results;
    }

    /**
     * Parse the Gemini embedContent response body.
     *
     * @param string $rawBody
     *
     * @return float[]
     *
     * @throws AiRequestException When the response is missing embedding.values.
     */
    private function parseResponse(string $rawBody): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new AiRequestException('Gemini embeddings returned a non-JSON response body', 0, $rawBody);
        }

        $embedding = $decoded['embedding'] ?? null;

        if (!is_array($embedding)) {
            throw new AiRequestException('Gemini embeddings response missing embedding field', 0, $rawBody);
        }

        $values = $embedding['values'] ?? null;

        if (!is_array($values)) {
            throw new AiRequestException('Gemini embeddings response missing embedding.values', 0, $rawBody);
        }

        return array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $values);
    }
}
