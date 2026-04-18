<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\EmbeddingClientInterface;
use EzPhp\HttpClient\HttpClient;

/**
 * Embedding driver for the OpenAI embeddings API.
 *
 * Calls POST /v1/embeddings and returns the float vector from data[0].embedding.
 *
 * @package EzPhp\Ai\Driver
 */
final class OpenAiEmbeddingDriver implements EmbeddingClientInterface
{
    public const string DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * @param HttpClient   $http   Injected HTTP client; use FakeTransport in tests.
     * @param OpenAiConfig $config Driver configuration.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly OpenAiConfig $config,
    ) {
    }

    /**
     * Embed a text input using the OpenAI embeddings endpoint.
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
        $url = $this->config->baseUrl() . '/v1/embeddings';

        $body = [
            'input' => $input,
            'model' => $model ?? self::DEFAULT_EMBEDDING_MODEL,
        ];

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->config->apiKey(),
                'Content-Type' => 'application/json',
            ])
            ->withBody((string) json_encode($body))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return $this->parseResponse($httpResponse->body());
    }

    /**
     * Embed multiple texts in a single OpenAI embeddings request.
     *
     * OpenAI accepts a list of strings as the `input` field, returning
     * one embedding per input ordered by index.
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
        $url = $this->config->baseUrl() . '/v1/embeddings';

        $body = [
            'input' => $inputs,
            'model' => $model ?? self::DEFAULT_EMBEDDING_MODEL,
        ];

        $httpResponse = $this->http
            ->post($url)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->config->apiKey(),
                'Content-Type' => 'application/json',
            ])
            ->withBody((string) json_encode($body))
            ->send();

        if (!$httpResponse->ok()) {
            throw AiRequestException::fromResponse($httpResponse->status(), $httpResponse->body());
        }

        return $this->parseBatchResponse($httpResponse->body(), count($inputs));
    }

    /**
     * Parse the OpenAI embeddings response body.
     *
     * @param string $rawBody
     *
     * @return float[]
     *
     * @throws AiRequestException When the response is missing data[0].embedding.
     */
    private function parseResponse(string $rawBody): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new AiRequestException('OpenAI embeddings returned a non-JSON response body', 0, $rawBody);
        }

        $data = $decoded['data'] ?? null;

        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            throw new AiRequestException('OpenAI embeddings response missing data[0]', 0, $rawBody);
        }

        $embedding = $data[0]['embedding'] ?? null;

        if (!is_array($embedding)) {
            throw new AiRequestException('OpenAI embeddings response missing data[0].embedding', 0, $rawBody);
        }

        return array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $embedding);
    }

    /**
     * Parse a batch embeddings response into an ordered list of vectors.
     *
     * @param string $rawBody
     * @param int    $expectedCount
     *
     * @return list<float[]>
     *
     * @throws AiRequestException When any embedding in the batch is missing.
     */
    private function parseBatchResponse(string $rawBody, int $expectedCount): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new AiRequestException('OpenAI embeddings returned a non-JSON response body', 0, $rawBody);
        }

        $data = $decoded['data'] ?? null;

        if (!is_array($data)) {
            throw new AiRequestException('OpenAI batch embeddings response missing data', 0, $rawBody);
        }

        $result = array_fill(0, $expectedCount, []);

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $index = $item['index'] ?? null;
            $embedding = $item['embedding'] ?? null;

            if (!is_int($index) || !is_array($embedding)) {
                throw new AiRequestException('OpenAI batch embeddings response has malformed item', 0, $rawBody);
            }

            $result[$index] = array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $embedding);
        }

        return array_values($result);
    }
}
