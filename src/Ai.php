<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Driver\NullDriver;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;

/**
 * Static façade for the active AiClientInterface instance.
 *
 * The client is wired by AiServiceProvider on boot. Without a service provider,
 * the façade falls back to a NullDriver that always returns an empty response.
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
     * @return AiClientInterface
     */
    public static function getClient(): AiClientInterface
    {
        if (self::$client === null) {
            self::$client = NullDriver::withContent('');
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
}
