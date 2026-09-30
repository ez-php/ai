<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;

/**
 * Static façade for the active AiClientInterface instance.
 *
 * The client is wired by AiServiceProvider on boot. Without it, every call
 * throws a RuntimeException naming the missing provider — a silent NullDriver
 * fallback returned empty completions and embeddings instead of failing.
 *
 * Usage after AiServiceProvider registration:
 *
 *   $response = Ai::complete(AiRequest::make('Tell me a joke.'));
 *   echo $response->content();
 *
 * In tests, wire any driver directly:
 *
 *   Ai::setClient(NullDriver::withContent('ok'));
 *   // ... test code ...
 *   Ai::resetClient();
 *
 * @package EzPhp\Ai
 */
final class Ai
{
    private static ?AiClientInterface $client = null;

    private static ?EmbeddingClientInterface $embeddingClient = null;

    // ─── Static client management ─────────────────────────────────────────────

    /**
     * @param AiClientInterface $client
     *
     * @return void
     */
    public static function setClient(AiClientInterface $client): void
    {
        self::$client = $client;
    }

    /**
     * @throws \RuntimeException When no client has been set (AiServiceProvider not registered).
     *
     * @return AiClientInterface
     */
    public static function getClient(): AiClientInterface
    {
        if (self::$client === null) {
            throw new \RuntimeException('Ai client not set. Did you register AiServiceProvider?');
        }

        return self::$client;
    }

    /**
     * Reset the static client (useful in tests).
     *
     * @return void
     */
    public static function resetClient(): void
    {
        self::$client = null;
    }

    /**
     * @param EmbeddingClientInterface $client
     *
     * @return void
     */
    public static function setEmbeddingClient(EmbeddingClientInterface $client): void
    {
        self::$embeddingClient = $client;
    }

    /**
     * @throws \RuntimeException When no embedding client has been set (AiServiceProvider not registered).
     *
     * @return EmbeddingClientInterface
     */
    public static function getEmbeddingClient(): EmbeddingClientInterface
    {
        if (self::$embeddingClient === null) {
            throw new \RuntimeException('Ai embedding client not set. Did you register AiServiceProvider?');
        }

        return self::$embeddingClient;
    }

    /**
     * Reset the static embedding client (useful in tests).
     *
     * @return void
     */
    public static function resetEmbeddingClient(): void
    {
        self::$embeddingClient = null;
    }

    // ─── Static façade ────────────────────────────────────────────────────────

    /**
     * Send a completion request using the active driver.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP error or malformed provider response.
     */
    public static function complete(AiRequest $request): AiResponse
    {
        return self::getClient()->complete($request);
    }

    /**
     * Embed a text input using the active embedding driver.
     *
     * @param string      $input
     * @param string|null $model Override the driver's default embedding model.
     *
     * @return float[]
     *
     * @throws AiRequestException On HTTP error or malformed provider response.
     */
    public static function embed(string $input, ?string $model = null): array
    {
        return self::getEmbeddingClient()->embed($input, $model);
    }

    /**
     * Embed multiple text inputs using the active embedding driver.
     *
     * @param list<string> $inputs
     * @param string|null  $model  Override the driver's default embedding model.
     *
     * @return list<float[]>
     *
     * @throws AiRequestException On HTTP error or malformed provider response.
     */
    public static function embedBatch(array $inputs, ?string $model = null): array
    {
        return self::getEmbeddingClient()->embedBatch($inputs, $model);
    }
}
