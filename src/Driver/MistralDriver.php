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
 * AI completion driver for the Mistral API.
 *
 * Mistral exposes an OpenAI-compatible /v1/chat/completions endpoint.
 * This driver delegates all request/response handling to OpenAiDriver,
 * configured with the Mistral base URL and API key.
 *
 * @package EzPhp\Ai\Driver
 */
final class MistralDriver implements StreamingAiClientInterface
{
    private readonly OpenAiDriver $inner;

    /**
     * @param HttpClient    $http   Injected HTTP client; use FakeTransport in tests.
     * @param MistralConfig $config Driver configuration.
     */
    public function __construct(HttpClient $http, MistralConfig $config)
    {
        $this->inner = new OpenAiDriver(
            $http,
            new OpenAiConfig($config->apiKey(), $config->model(), $config->baseUrl()),
        );
    }

    /**
     * Send a streaming completion request to Mistral and return an AiStream.
     *
     * Delegates to OpenAiDriver since Mistral is OpenAI-compatible.
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
     * Send a completion request to Mistral and return the parsed response.
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
