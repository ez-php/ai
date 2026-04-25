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
     * Send a streaming completion request and return a lazy AiStream.
     *
     * The stream is backed by a Generator that parses the SSE response body.
     * Iteration starts parsing on the first `foreach` or `collect()` call.
     *
     * @param AiRequest $request
     *
     * @return AiStream
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function stream(AiRequest $request): AiStream;
}
