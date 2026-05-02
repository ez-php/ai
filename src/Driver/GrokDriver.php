<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\AiStream;
use EzPhp\Ai\StreamingAiClientInterface;
use EzPhp\HttpClient\HttpClient;

/**
 * AI completion driver for the Grok (xAI) API.
 *
 * Grok exposes an OpenAI-compatible /v1/chat/completions endpoint.
 * This driver delegates all request/response handling to OpenAiDriver,
 * configured with the xAI base URL and API key.
 *
 * @package EzPhp\Ai\Driver
 */
final class GrokDriver implements StreamingAiClientInterface
{
    private readonly OpenAiDriver $inner;

    /**
     * @param HttpClient $http   Injected HTTP client; use FakeTransport in tests.
     * @param GrokConfig $config Driver configuration.
     */
    public function __construct(HttpClient $http, GrokConfig $config)
    {
        $this->inner = new OpenAiDriver(
            $http,
            new OpenAiConfig($config->apiKey(), $config->model(), $config->baseUrl()),
        );
    }

    /**
     * Send a streaming completion request to Grok and return an AiStream.
     *
     * Delegates to OpenAiDriver since Grok is OpenAI-compatible.
     *
     * @param AiRequest $request
     *
     * @return AiStream
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function stream(AiRequest $request): AiStream
    {
        return $this->inner->stream($request);
    }

    /**
     * Send a completion request to Grok and return the parsed response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function complete(AiRequest $request): AiResponse
    {
        return $this->inner->complete($request);
    }
}
