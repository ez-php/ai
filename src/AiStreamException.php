<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use Throwable;

/**
 * Thrown while iterating an AiStream when the stream cannot complete.
 *
 * Three causes: the transport failed mid-stream (the HttpStreamException is
 * the previous exception), the provider sent an error event, or the stream
 * ended without the provider's completion signal (silent truncation).
 *
 * @package EzPhp\Ai
 */
final class AiStreamException extends AiException
{
    public const string TRUNCATED_MESSAGE = 'AI stream ended before the provider signalled completion.';

    private const string DEFAULT_PROVIDER_MESSAGE = 'AI provider reported a stream error.';

    /**
     * @param string         $message
     * @param string|null    $providerErrorType Provider error type, e.g. "overloaded_error"; null for transport failures and truncation.
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly ?string $providerErrorType = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The stream ended cleanly but without the provider's terminator.
     *
     * @return self
     */
    public static function truncated(): self
    {
        return new self(self::TRUNCATED_MESSAGE);
    }

    /**
     * Build from a provider's `error` object.
     *
     * @param array<mixed> $error   The decoded `error` object of the event.
     * @param string       $typeKey Key holding the error type (`type` for OpenAI/Anthropic, `status` for Gemini).
     *
     * @return self
     */
    public static function fromProviderError(array $error, string $typeKey = 'type'): self
    {
        $message = $error['message'] ?? null;
        $type = $error[$typeKey] ?? null;

        return new self(
            is_string($message) && $message !== '' ? $message : self::DEFAULT_PROVIDER_MESSAGE,
            is_string($type) ? $type : null,
        );
    }

    /**
     * @return string|null
     */
    public function providerErrorType(): ?string
    {
        return $this->providerErrorType;
    }
}
