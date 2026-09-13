<?php

declare(strict_types=1);

namespace EzPhp\Ai;

use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiStream;

/**
 * Contract for drivers that support server-sent-event streaming.
 *
 * Drivers that implement this interface send the request with streaming enabled
 * and parse the SSE response body into an AiStream of AiChunk objects.
 *
 * @package EzPhp\Ai
 */
interface StreamingAiClientInterface extends AiClientInterface
{
    /**
     * Default idle timeout for streamed completions, in seconds.
     *
     * Longer than ez-php/http-client's 30 s default: reasoning models may send
     * nothing for a long time before the first token.
     */
    public const int DEFAULT_STREAM_IDLE_TIMEOUT_SECONDS = 120;

    /**
     * Send a streaming completion request and return a lazy AiStream.
     *
     * Returns once the provider's response headers arrived. The stream is
     * backed by a Generator that parses SSE events as the provider sends them.
     * Iterating it throws AiStreamException when the transfer fails, the
     * provider reports an error, or the stream ends without completion.
     *
     * @param AiRequest $request
     *
     * @return AiStream
     *
     * @throws AiRequestException On an HTTP error status.
     * @throws \EzPhp\HttpClient\HttpClientException When the connection fails before the response headers arrive.
     */
    public function stream(AiRequest $request): AiStream;
}
