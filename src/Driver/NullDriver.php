<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;

/**
 * A driver that always returns a pre-configured canned response without making any network calls.
 *
 * Intended for testing and local development.
 *
 * @package EzPhp\Ai\Driver
 */
final class NullDriver implements AiClientInterface
{
    /**
     * @param AiResponse $response The response returned for every request.
     */
    public function __construct(private readonly AiResponse $response)
    {
    }

    /**
     * Create a NullDriver that returns a response with the given content string.
     *
     * @param string $content
     *
     * @return self
     */
    public static function withContent(string $content): self
    {
        return new self(
            new AiResponse($content, FinishReason::STOP, new TokenUsage(0, 0), '{}'),
        );
    }

    /**
     * Return the pre-configured response, ignoring the request.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     */
    public function complete(AiRequest $request): AiResponse
    {
        return $this->response;
    }
}
