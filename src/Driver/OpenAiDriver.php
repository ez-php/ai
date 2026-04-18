<?php

declare(strict_types=1);

namespace EzPhp\Ai\Driver;

use EzPhp\Ai\AiClientInterface;
use EzPhp\Ai\AiRequestException;
use EzPhp\Ai\Message\AiMessage;
use EzPhp\Ai\Message\ContentPart;
use EzPhp\Ai\Message\ContentPartType;
use EzPhp\Ai\Request\AiRequest;
use EzPhp\Ai\Response\AiResponse;
use EzPhp\Ai\Response\FinishReason;
use EzPhp\Ai\Response\TokenUsage;
use EzPhp\HttpClient\HttpClient;

/**
 * AI completion driver for the OpenAI chat completions API.
 *
 * Calls POST /v1/chat/completions with the standard JSON body format.
 * Uses ez-php/http-client for all HTTP I/O — never constructs its own transport.
 *
 * @package EzPhp\Ai\Driver
 */
final class OpenAiDriver implements AiClientInterface
{
    /**
     * @param HttpClient    $http   Injected HTTP client; use FakeTransport in tests.
     * @param OpenAiConfig  $config Driver configuration.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly OpenAiConfig $config,
    ) {
    }

    /**
     * Send a completion request to OpenAI and return the parsed response.
     *
     * @param AiRequest $request
     *
     * @return AiResponse
     *
     * @throws AiRequestException On HTTP error or malformed response body.
     */
    public function complete(AiRequest $request): AiResponse
    {
        $body = $this->buildBody($request);
        $url = $this->config->baseUrl() . '/v1/chat/completions';

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
     * Build the OpenAI request body from an AiRequest.
     *
     * @param AiRequest $request
     *
     * @return array<string, mixed>
     */
    private function buildBody(AiRequest $request): array
    {
        $messages = [];

        if ($request->systemPrompt() !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt()];
        }

        foreach ($request->messages() as $message) {
            $messages[] = $this->serializeMessage($message);
        }

        /** @var array<string, mixed> $body */
        $body = [
            'model' => $request->model() ?? $this->config->model(),
            'messages' => $messages,
        ];

        if ($request->temperature() !== null) {
            $body['temperature'] = $request->temperature();
        }

        if ($request->maxTokens() !== null) {
            $body['max_tokens'] = $request->maxTokens();
        }

        return $body;
    }

    /**
     * Serialize an AiMessage to the OpenAI messages array entry format.
     *
     * @param AiMessage $message
     *
     * @return array<string, mixed>
     */
    private function serializeMessage(AiMessage $message): array
    {
        $content = $message->content();

        if (is_string($content)) {
            return ['role' => $message->role()->value, 'content' => $content];
        }

        $parts = [];

        foreach ($content as $part) {
            $parts[] = $this->serializePart($part);
        }

        return ['role' => $message->role()->value, 'content' => $parts];
    }

    /**
     * Serialize a ContentPart to the OpenAI content part format.
     *
     * @param ContentPart $part
     *
     * @return array<string, mixed>
     */
    private function serializePart(ContentPart $part): array
    {
        if ($part->type() === ContentPartType::IMAGE_URL) {
            return ['type' => 'image_url', 'image_url' => ['url' => $part->content()]];
        }

        return ['type' => 'text', 'text' => $part->content()];
    }

    /**
     * Parse an OpenAI JSON response body into an AiResponse.
     *
     * @param string $rawBody
     *
     * @return AiResponse
     *
     * @throws AiRequestException When the response body is missing required fields.
     */
    private function parseResponse(string $rawBody): AiResponse
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new AiRequestException('OpenAI returned a non-JSON response body', 0, $rawBody);
        }

        $choices = $decoded['choices'] ?? null;

        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw new AiRequestException('OpenAI response missing choices[0]', 0, $rawBody);
        }

        $choice = $choices[0];
        $message = $choice['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (!is_string($content)) {
            throw new AiRequestException('OpenAI response missing choices[0].message.content', 0, $rawBody);
        }

        $finishReason = $this->mapFinishReason(
            is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : '',
        );

        $usageRaw = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $inputTokens = is_int($usageRaw['prompt_tokens'] ?? null) ? $usageRaw['prompt_tokens'] : 0;
        $outputTokens = is_int($usageRaw['completion_tokens'] ?? null) ? $usageRaw['completion_tokens'] : 0;
        $usage = new TokenUsage($inputTokens, $outputTokens);

        return new AiResponse($content, $finishReason, $usage, $rawBody);
    }

    /**
     * Map an OpenAI finish_reason string to a FinishReason enum case.
     *
     * @param string $reason
     *
     * @return FinishReason
     */
    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::STOP,
            'length' => FinishReason::LENGTH,
            'tool_calls' => FinishReason::TOOL_CALL,
            'content_filter' => FinishReason::CONTENT_FILTER,
            default => FinishReason::ERROR,
        };
    }
}
