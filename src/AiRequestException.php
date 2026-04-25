<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use Throwable;

/**
 * Thrown when an AI provider returns an HTTP error or a malformed response.
 *
 * Not thrown for successful responses regardless of content — check
 * AiResponse::finishReason() for model-level errors.
 *
 * @package EzPhp\Ai
 */
class AiRequestException extends AiException
{
    /**
     * @param string         $message
     * @param int            $statusCode   HTTP status code returned by the provider (0 if unavailable).
     * @param string         $responseBody Raw response body from the provider.
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly string $responseBody = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Create an exception from a provider HTTP error response.
     *
     * @param int    $statusCode
     * @param string $body
     * @param string $message    Optional override; defaults to a generic description.
     *
     * @return self
     */
    public static function fromResponse(int $statusCode, string $body, string $message = ''): self
    {
        if ($message === '') {
            $message = sprintf('AI provider returned HTTP %d', $statusCode);
        }

        return new self($message, $statusCode, $body);
    }

    /**
     * HTTP status code from the provider (0 when no HTTP response was received).
     *
     * @return int
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Raw response body returned by the provider.
     *
     * @return string
     */
    public function responseBody(): string
    {
        return $this->responseBody;
    }
}
